"""Run on Linux with real PHP/flock; no application DB or external HTTP calls."""
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SITE = ROOT/'deploy/site/oderland-staging'
spec = importlib.util.spec_from_file_location('staging_site_control', SITE/'site-control.py')
site = importlib.util.module_from_spec(spec)
spec.loader.exec_module(site)
PHP = os.environ.get('SITE_TEST_PHP', '/opt/alt/php84/usr/bin/php')

class StagingWriterProtocolTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='nordipass-writer-gate-test-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.framework = self.base/'shared/storage/framework'
        self.directory = self.framework/'nordipass-writer-gate'
        (self.directory/'seen').mkdir(parents=True)
        (self.directory/'http.lock').write_text('')
        self.gate = self.framework/'maintenance.php'
        self.gate.write_text((SITE/'http-writer-gate.php').read_text().replace(str(site.BASE), str(self.base)))

    def php(self, suffix='echo "BOOTSTRAPPED";', prefix=''):
        return subprocess.Popen([PHP, '-r', prefix + 'require '+json.dumps(str(self.gate))+';'+suffix],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

    def lock(self):
        stream = (self.directory/'http.lock').open('r+')
        self.addCleanup(stream.close)
        return stream

    def wait_seen(self, process):
        import time
        deadline = time.monotonic()+5
        start = site.process_start(process.pid)
        while not (self.directory/'seen'/f'{process.pid}-{start}').exists():
            if process.poll() is not None or time.monotonic() > deadline:
                self.fail('PHP did not reach the gate')
            time.sleep(0.01)

    def test_normal_request_bootstraps_and_records_process_identity(self):
        process = self.php()
        stdout, stderr = process.communicate(timeout=5)
        self.assertEqual(process.returncode, 0, stderr)
        self.assertEqual(stdout, 'BOOTSTRAPPED')
        self.assertEqual(len(list((self.directory/'seen').iterdir())), 1)

    def test_paused_request_exits_before_application_bootstrap(self):
        (self.base/'shared/writers-paused').write_text('paused')
        process = self.php(prefix='register_shutdown_function(function(){echo "STATUS=".http_response_code();});')
        stdout, stderr = process.communicate(timeout=5)
        self.assertEqual(process.returncode, 0, stderr)
        self.assertNotIn('BOOTSTRAPPED', stdout)
        self.assertIn('STATUS=503', stdout)

    def test_http_lease_covers_late_shutdown_functions(self):
        process = self.php('register_shutdown_function(function(){echo "SHUTDOWN\\n";flush();usleep(700000);});')
        try:
            self.assertEqual(process.stdout.readline().strip(), 'SHUTDOWN')
            stream = self.lock()
            with self.assertRaises(BlockingIOError):
                fcntl.flock(stream, fcntl.LOCK_EX|fcntl.LOCK_NB)
            stdout, stderr = process.communicate(timeout=5)
            self.assertEqual(process.returncode, 0, stderr)
            fcntl.flock(stream, fcntl.LOCK_EX|fcntl.LOCK_NB)
        finally:
            if process.poll() is None:
                process.kill(); process.communicate()

    def test_pause_arriving_while_request_waits_for_lock_is_rechecked(self):
        stream = self.lock()
        fcntl.flock(stream, fcntl.LOCK_EX)
        process = self.php()
        try:
            self.wait_seen(process)
            (self.base/'shared/writers-paused').write_text('paused')
            fcntl.flock(stream, fcntl.LOCK_UN)
            stdout, stderr = process.communicate(timeout=5)
            self.assertEqual(process.returncode, 0, stderr)
            self.assertNotIn('BOOTSTRAPPED', stdout)
            self.assertIn('temporarily unavailable', stdout)
        finally:
            if process.poll() is None:
                process.kill(); process.communicate()

    def test_missing_runtime_directory_fails_closed(self):
        (self.directory/'seen').rmdir()
        process = self.php()
        stdout, stderr = process.communicate(timeout=5)
        self.assertNotIn('BOOTSTRAPPED', stdout)

    def test_pid_reuse_does_not_leave_a_false_legacy_blocker(self):
        identity = {'legacy_web_processes': [{'pid': 123, 'start': '100'}]}
        with patch.object(site, 'process_start', return_value='101'):
            self.assertEqual(site.legacy_pending(identity, self.directory), [])

    def test_live_legacy_process_must_be_seen_through_gate(self):
        identity = {'legacy_web_processes': [{'pid': 123, 'start': '100'}]}
        with patch.object(site, 'process_start', return_value='100'):
            self.assertEqual(site.legacy_pending(identity, self.directory), [123])
            (self.directory/'seen/123-100').write_text('v1')
            self.assertEqual(site.legacy_pending(identity, self.directory), [])

    def test_paused_http_requires_specific_gate_header(self):
        import urllib.error
        for headers, expected in (({}, False), ({'X-NordiPass-Writer-Gate': 'v1'}, True)):
            error = urllib.error.HTTPError('https://fixture.invalid/up', 503, 'Paused', headers, None)
            with patch.object(site.urllib.request, 'urlopen', side_effect=error):
                self.assertEqual(site.paused_http(), expected)

    def test_unrelated_root_is_rejected_before_inspecting_state(self):
        with self.assertRaisesRegex(RuntimeError, 'explicit staging root'):
            site.validate('/home/codecraf/production')

    def test_deployment_cron_pins_are_complete_sha256_digests(self):
        self.assertEqual(set(site.CRON_SHA256), {'scheduler', 'worker'})
        for digest in site.CRON_SHA256.values():
            self.assertRegex(digest, r'^[0-9a-f]{64}$')

    def test_changed_cron_writer_is_rejected_after_valid_inventory(self):
        scripts = self.base/'cron'
        scripts.mkdir()
        original = b'#!/bin/bash\nexit 0\n'
        for kind in ('scheduler', 'worker'):
            (scripts/f'{kind}.sh').write_bytes(original)
        pins = {kind: hashlib.sha256(original).hexdigest() for kind in ('scheduler', 'worker')}
        with patch.object(site, 'CRON_SHA256', pins):
            site.verify_cron_scripts(scripts)
            (scripts/'worker.sh').write_bytes(original+b'exec unexpected-writer\n')
            with self.assertRaisesRegex(RuntimeError, 'Cron entry script changed'):
                site.verify_cron_scripts(scripts)

    def test_invalid_cron_digest_is_rejected_before_reading_scripts(self):
        with patch.object(site, 'CRON_SHA256', {'scheduler': 'f'*65}):
            with self.assertRaisesRegex(RuntimeError, 'Invalid pinned cron checksum'):
                site.verify_cron_scripts(self.base/'absent')

    def capacity_state(self, rows):
        helper = json.dumps(str(SITE/'capacity-state.php'))
        payload = json.dumps(json.dumps(rows))
        result = subprocess.run([PHP, '-r', '$check=require '+helper+';echo json_encode($check(json_decode('+payload+')));'],
                                capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def test_lazy_null_capacity_is_preserved_as_unknown(self):
        rows = [{'capacity_company_id': 1, 'stored_managed': None, 'stored_total': 7,
                 'actual_managed': 3, 'actual_total': 7},
                {'capacity_company_id': 2, 'stored_managed': 0, 'stored_total': None,
                 'actual_managed': 0, 'actual_total': 9}]
        result = self.capacity_state(rows)
        self.assertTrue(result['valid'])
        self.assertEqual(result['uninitialized_managed'], 1)
        self.assertEqual(result['uninitialized_total'], 1)
        self.assertEqual(result['drift_rows'], 0)

    def test_numeric_capacity_drift_and_malformed_values_are_rejected(self):
        for stored in (2, -1, 'not-a-count'):
            result = self.capacity_state([{'capacity_company_id': 1, 'stored_managed': stored,
                                          'stored_total': 7, 'actual_managed': 3, 'actual_total': 7}])
            self.assertFalse(result['valid'])
            self.assertEqual(result['drift_rows'], 1)

    def test_missing_capacity_row_is_not_a_lazy_null_counter(self):
        result = self.capacity_state([{'capacity_company_id': None, 'stored_managed': None,
                                      'stored_total': None, 'actual_managed': 0, 'actual_total': 0}])
        self.assertFalse(result['valid'])
        self.assertEqual(result['missing_rows'], 1)

    def test_unknown_legacy_worker_refuses_pause_before_creating_marker(self):
        with patch.object(site, 'legacy_pending', return_value=[123]), patch.object(site, 'state') as inspect:
            with self.assertRaisesRegex(RuntimeError, 'no pause imposed'):
                site.pause(self.base/'shared', self.directory, {})
            inspect.assert_not_called()
            self.assertFalse((self.base/'shared/writers-paused').exists())

    def prepare_pause(self):
        record = {'kind': 'nordipass-staging-writer-pause-v1', 'pause_id': '1234567890123456789'}
        (self.base/'shared/writers-paused').write_text(json.dumps(record))
        return record

    def test_resume_timeout_restores_pause_without_database_probe(self):
        record = self.prepare_pause()
        with patch.object(site, 'verify', return_value=record), \
                patch.object(site.subprocess, 'run', side_effect=subprocess.TimeoutExpired('fixture', 360)), \
                patch.object(site, 'state') as inspect:
            with self.assertRaises(subprocess.TimeoutExpired):
                site.resume(self.base/'shared', self.directory, {})
            inspect.assert_not_called()
        self.assertEqual(json.loads((self.base/'shared/writers-paused').read_text()), record)

    def test_resume_failed_cron_restores_pause_without_database_probe(self):
        record = self.prepare_pause()
        with patch.object(site, 'verify', return_value=record), \
                patch.object(site.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1)), \
                patch.object(site, 'state') as inspect:
            with self.assertRaisesRegex(RuntimeError, 'Fresh cron entry failed'):
                site.resume(self.base/'shared', self.directory, {})
            inspect.assert_not_called()
        self.assertTrue((self.base/'shared/writers-paused').exists())

    def test_backup_rejects_proof_from_another_pause_and_preserves_marker(self):
        record = self.prepare_pause()
        (self.directory/'backup-proof.json').write_text(json.dumps({'pause_id': '987654321', 'completed': True}))
        with patch.object(site, 'verify', return_value=record), \
                patch.object(site, 'lock_all'), \
                patch.object(site.subprocess, 'run', return_value=subprocess.CompletedProcess([], 0)):
            with self.assertRaisesRegex(RuntimeError, 'current writer boundary'):
                site.backup(self.base/'shared', self.directory, {})
        self.assertTrue((self.base/'shared/writers-paused').exists())

    def test_backup_failed_restore_preserves_marker_and_does_not_accept_old_proof(self):
        record = self.prepare_pause()
        with patch.object(site, 'verify', return_value=record), \
                patch.object(site, 'lock_all'), \
                patch.object(site.subprocess, 'run', return_value=subprocess.CompletedProcess([], 1)):
            with self.assertRaisesRegex(RuntimeError, 'writers remain paused'):
                site.backup(self.base/'shared', self.directory, {})
        self.assertTrue((self.base/'shared/writers-paused').exists())

    def completed_backup_fixture(self):
        import hashlib
        record = self.prepare_pause()
        source = self.base/'private-backups/database.sql.gz'
        source.parent.mkdir()
        source.write_bytes(b'isolated fixture backup')
        returned = source.parent/'offhost-return'/source.name
        returned.parent.mkdir()
        returned.write_bytes(source.read_bytes())
        proof = {**record, 'completed': True, 'backup_id': 'fixture-backup', 'source_release': 'fixture-release',
                 'retained_artifacts': [{'path': str(source), 'bytes': source.stat().st_size,
                                        'sha256': hashlib.sha256(source.read_bytes()).hexdigest()}]}
        (self.directory/'backup-proof.json').write_text(json.dumps(proof))
        drain = {**record, 'state': {'release': 'fixture-release'}}
        return proof, drain, returned

    def test_completed_restore_without_offhost_proof_is_not_release_acceptance(self):
        proof, drain, returned = self.completed_backup_fixture()
        with patch.object(site, 'verify', return_value=drain), patch.object(site, 'lock_all'), \
                patch.object(site.subprocess, 'run') as native:
            with self.assertRaisesRegex(RuntimeError, 'Off-host backup verification still required'):
                site.backup(self.base/'shared', self.directory, {})
            native.assert_not_called()
        self.assertTrue((self.base/'shared/writers-paused').exists())

    def test_same_boundary_accepts_verified_returned_bytes_without_overwriting_backup(self):
        proof, drain, returned = self.completed_backup_fixture()
        receipt = {**proof, 'off_host_verified': True}
        receipt['artifacts'] = proof['retained_artifacts']
        (self.directory/f"backup-offhost-{proof['pause_id']}.json").write_text(json.dumps(receipt))
        with patch.object(site, 'verify', return_value=drain), patch.object(site, 'lock_all'), \
                patch.object(site.subprocess, 'run') as native:
            self.assertEqual(site.backup(self.base/'shared', self.directory, {}), proof)
            native.assert_not_called()

    def test_offhost_receipt_cannot_hide_changed_returned_bytes(self):
        proof, drain, returned = self.completed_backup_fixture()
        receipt = {**proof, 'off_host_verified': True, 'artifacts': proof['retained_artifacts']}
        (self.directory/f"backup-offhost-{proof['pause_id']}.json").write_text(json.dumps(receipt))
        returned.write_bytes(b'corrupt')
        with patch.object(site, 'verify', return_value=drain), patch.object(site, 'lock_all'):
            with self.assertRaisesRegex(RuntimeError, 'integrity failed'):
                site.backup(self.base/'shared', self.directory, {})
        self.assertTrue((self.base/'shared/writers-paused').exists())

    def test_native_heartbeat_freshness_uses_last_run_not_forever_cache_expiration(self):
        helper = json.dumps(str(SITE/'heartbeat-state.php'))
        php = '$parse=require '+helper+';'
        php += '$now=(new DateTimeImmutable("2026-10-09T14:00:00+00:00"))->getTimestamp();'
        php += '$out=[];foreach(["2026-10-09T13:59:00+00:00","2026-10-09T13:00:00+00:00","2026-10-09T15:00:00+00:00"] as $value){'
        php += '$out[]=$parse((object)["value"=>serialize($value),"expiration"=>$now+315360000],180,$now)["fresh"];} '
        php += '$out[]=$parse((object)["value"=>serialize("2026-10-09T13:59:00+00:00"),"expiration"=>$now-1],180,$now)["fresh"];'
        php += '$out[]=$parse((object)["value"=>"O:8:stdClass:0:{}","expiration"=>$now+1000],180,$now)["fresh"];echo json_encode($out);'
        result = subprocess.run([PHP, '-r', php], capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), [True, False, False, False, False])

if __name__ == '__main__':
    unittest.main(verbosity=2)
