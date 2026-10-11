"""Execute release scripts against owned fixture directories and fake site services."""
import importlib.util
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

REPO = Path(__file__).parents[2]
SPEC = importlib.util.spec_from_file_location('release_artifact', REPO / 'deploy/scripts/release_artifact.py')
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)


@unittest.skipUnless(os.name == 'posix' and shutil.which('flock'), 'Lifecycle execution requires Linux flock and native symlinks')
class DeploymentLifecycleTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix='nordipass-release-test-')
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.app = self.root / 'app'
        self.app.mkdir()
        for name in ['app', 'framework', 'logs']:
            (self.app / 'shared/storage' / name).mkdir(parents=True)
        (self.app / 'shared/.env').write_text('fixture-only')
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.log = self.root / 'events'
        self.environment = os.environ.copy()
        self.environment.update({'PATH': str(self.bin) + os.pathsep + os.environ['PATH'], 'TEST_EVENTS': str(self.log)})
        self.hooks = {}
        for name in ['pause', 'stopped', 'backup', 'resume', 'release']:
            path = self.bin / name
            path.write_text('#!/usr/bin/env bash\nset -eu\necho '+name+' >> "$TEST_EVENTS"\n[[ "${TEST_FAIL_HOOK:-}" != '+name+' ]]\n')
            path.chmod(0o755)
            self.hooks[name] = path
        self.script('php', '''echo "php:${2:-}" >> "$TEST_EVENTS"
[[ "${TEST_FAIL_PHP:-}" != "${2:-}" ]]
''')
        self.script('curl', '''echo "health" >> "$TEST_EVENTS"
[[ "${TEST_HEALTH_FAIL:-false}" != true ]]
''')
        self.script('sleep', 'exit 0\n')
        self.source = self.root / 'source'
        for name, value in {'artisan': 'fixture', 'app/Rule.php': '<?php', 'composer.lock': '{}',
                            'vendor/autoload.php': '<?php', 'public/build/manifest.json': '{}'}.items():
            path = self.source / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(value)
        self.artifact = self.root / 'release.tar.gz'
        release.build(self.source, self.artifact, 'a'*40, 'fixture', '8.4.26', '22.20.0', '2026-10-04T00:00:00Z')
        self.checksum = Path(str(self.artifact) + '.sha256')

    def script(self, name, body):
        path = self.bin / name
        path.write_text('#!/usr/bin/env bash\nset -eu\n' + body)
        path.chmod(0o755)

    def events(self):
        return self.log.read_text().splitlines() if self.log.exists() else []

    def run_script(self, script, release_id, extra=(), environment=None):
        command = ['bash', str(REPO / 'deploy/scripts' / script), '--release-id', release_id,
                   '--app-root', str(self.app), '--health-url', 'http://fixture.invalid',
                   '--pause-writers', str(self.hooks['pause']), '--verify-writers-stopped', str(self.hooks['stopped']),
                   '--verify-backup', str(self.hooks['backup']), '--resume-writers', str(self.hooks['resume']),
                   '--verify-release', str(self.hooks['release'])]
        if script == 'deploy.sh':
            command.extend(['--artifact', str(self.artifact), '--checksum-file', str(self.checksum)])
        return subprocess.run(command + list(extra), env=environment or self.environment, capture_output=True, text=True, timeout=60)

    def test_deploy_orders_drain_backup_schema_and_fresh_workers_and_retains_original(self):
        result = self.run_script('deploy.sh', 'first')
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        events = self.events()
        for before, after in [('pause', 'stopped'), ('stopped', 'backup'), ('backup', 'php:migrate'),
                              ('php:migrate', 'php:release:verify-schema'), ('php:release:verify-schema', 'resume'), ('resume', 'health')]:
            self.assertLess(events.index(before), events.index(after))
        self.assertEqual((self.app / 'current').resolve(), self.app / 'releases/first')
        self.assertTrue(self.artifact.exists())
        self.assertTrue(self.checksum.exists())
        self.assertEqual(release.verify_tree(self.app / 'current', self.app / 'artifacts')['commit'], 'a'*40)
        self.assertTrue((self.app / 'deploy.lock').exists())

    def test_failed_drain_or_backup_never_runs_migrations_or_resumes(self):
        for hook in ['stopped', 'backup']:
            with self.subTest(hook=hook):
                env = self.environment | {'TEST_FAIL_HOOK': hook}
                result = self.run_script('deploy.sh', 'blocked-'+hook, environment=env)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(hook, self.events())
                self.assertIn('pause', self.events())
                self.assertNotIn('php:migrate', self.events())
                self.assertNotIn('resume', self.events())
                self.assertFalse((self.app / 'current').exists())

    def test_schema_failure_leaves_writers_stopped_and_current_unswitched(self):
        result = self.run_script('deploy.sh', 'schema-fail', environment=self.environment | {'TEST_FAIL_PHP': 'release:verify-schema'})
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('php:migrate', self.events())
        self.assertIn('php:release:verify-schema', self.events())
        self.assertNotIn('resume', self.events())
        self.assertFalse((self.app / 'current').exists())

    def test_failed_health_never_reports_success_and_drains_again(self):
        result = self.run_script('deploy.sh', 'health-fail', environment=self.environment | {'TEST_HEALTH_FAIL': 'true'})
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('Deployment completed', result.stdout)
        self.assertEqual(self.events().count('pause'), 2)
        self.assertEqual(self.events().count('stopped'), 2)

    def test_checksum_failure_and_traversal_id_have_no_lifecycle_side_effects(self):
        with self.artifact.open('ab') as output:
            output.write(b'tamper')
        for identity in ['normal', '../escape', '..']:
            result = self.run_script('deploy.sh', identity)
            self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.events(), [])
        self.assertFalse((self.app / 'artifacts').exists())

    def test_live_lock_rejects_second_operation_without_unlinking_lock_inode(self):
        import fcntl
        lock = self.app / 'deploy.lock'
        with lock.open('w') as held:
            inode = lock.stat().st_ino
            fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_script('deploy.sh', 'contended')
            self.assertEqual(result.returncode, 3, result.stderr)
            self.assertIn('Another release operation', result.stderr)
            self.assertEqual(self.events(), [])
            self.assertEqual(lock.stat().st_ino, inode)
        result = self.run_script('deploy.sh', 'after-lock')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(lock.stat().st_ino, inode)

    def install_pair(self):
        self.assertEqual(self.run_script('deploy.sh', 'first').returncode, 0)
        self.assertEqual(self.run_script('deploy.sh', 'second').returncode, 0)
        self.log.unlink()

    def test_rollback_refuses_incompatible_schema_before_switching(self):
        self.install_pair()
        result = self.run_script('rollback.sh', 'first', ['--force'], self.environment | {'TEST_FAIL_PHP': 'release:verify-schema'})
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.app / 'current').resolve(), self.app / 'releases/second')
        self.assertIn('backup', self.events())
        self.assertIn('php:release:verify-schema', self.events())
        self.assertNotIn('resume', self.events())

    def test_rollback_health_failure_is_failure_and_drains_again(self):
        self.install_pair()
        result = self.run_script('rollback.sh', 'first', ['--force'], self.environment | {'TEST_HEALTH_FAIL': 'true'})
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('Rollback completed', result.stdout)
        self.assertEqual(self.events().count('pause'), 2)
        self.assertEqual(self.events().count('stopped'), 2)


    def test_unsafe_rollback_target_is_rejected_before_pausing_or_starting_php(self):
        self.install_pair()
        result = self.run_script('rollback.sh', 'first', ['--force'], self.environment | {'TEST_FAIL_HOOK': 'release'})
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.events(), ['release'])
        self.assertEqual((self.app / 'current').resolve(), self.app / 'releases/second')

    def test_unsafe_deploy_target_never_migrates_activates_or_resumes(self):
        result = self.run_script('deploy.sh', 'unsafe', environment=self.environment | {'TEST_FAIL_HOOK': 'release'})
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('backup', self.events())
        self.assertIn('release', self.events())
        self.assertFalse(any(event.startswith('php:') for event in self.events()))
        self.assertNotIn('resume', self.events())
        self.assertFalse((self.app / 'current').exists())


if __name__ == '__main__':
    unittest.main()
