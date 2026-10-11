"""Review integration safety without executing OAuth or touching live tenants."""
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('release_policy', Path(__file__).parents[2]/'deploy/site/oderland-staging/release-compatibility.py')
policy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(policy)


class ReleaseCompatibilityTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.base = Path(directory.name).resolve()/'staging'
        self.release = self.base/'releases/older'
        self.file = self.release/'app/Services/Fortnox/FortnoxSubscriptionGuard.php'
        self.file.parent.mkdir(parents=True)
        self.file.write_bytes(b'approved fixture guard')
        (self.base/'shared').mkdir()
        (self.base/'shared/.env').write_text('fixture, no credentials')
        (self.release/'.env').symlink_to(self.base/'shared/.env')
        self.pins = {'kind': 'reviewed_fortnox_nonproduction_source', 'files': {
            'app/Services/Fortnox/FortnoxSubscriptionGuard.php': hashlib.sha256(self.file.read_bytes()).hexdigest()}}
        self.patch = patch.object(policy, 'BASE', self.base)
        self.patch.start()
        self.addCleanup(self.patch.stop)

    def test_reviewed_guard_source_is_accepted(self):
        self.assertEqual(policy.verify_source(self.base, self.release, self.pins), self.release)

    def test_old_source_without_guard_is_rejected_before_php_or_network(self):
        self.file.unlink()
        with patch.object(policy.subprocess, 'run') as execute, self.assertRaisesRegex(ValueError, 'source is missing'):
            policy.verify_source(self.base, self.release, self.pins)
        execute.assert_not_called()

    def test_changed_guard_is_rejected_even_when_version_label_stays_same(self):
        self.file.write_bytes(b'production allowed')
        with self.assertRaisesRegex(ValueError, 'source changed'):
            policy.verify_source(self.base, self.release, self.pins)

    def test_other_root_and_escaped_release_are_rejected(self):
        with self.assertRaisesRegex(ValueError, 'explicit staging root'):
            policy.verify_source(self.base.parent, self.release, self.pins)
        with self.assertRaisesRegex(ValueError, 'owned staging release'):
            policy.verify_source(self.base, self.base/'shared', self.pins)

    def test_symlinked_safety_code_is_rejected(self):
        other = self.release/'other.php'
        other.write_bytes(self.file.read_bytes())
        self.file.unlink()
        self.file.symlink_to(other)
        with self.assertRaisesRegex(ValueError, 'source is missing'):
            policy.verify_source(self.base, self.release, self.pins)

    def test_unsafe_environment_or_enabled_oauth_is_rejected(self):
        report = {'environment': 'staging', 'oauth_enabled': False, 'expected_tenant': '1854146',
                  'tenant_outcomes': {'1853390': 'blocked', '1854146': 'accepted', 'unknown': 'blocked', '9999999': 'blocked'},
                  'new_authorization': 'blocked', 'no_external_requests_performed': True}
        self.assertEqual(policy.verify_runtime(report), report)
        for changes in ({'environment': 'production'}, {'oauth_enabled': True}, {'expected_tenant': '1853390'},
                        {'new_authorization': 'accepted'}, {'no_external_requests_performed': False},
                        {'tenant_outcomes': {**report['tenant_outcomes'], '1853390': 'accepted'}}):
            with self.subTest(changes=changes), self.assertRaisesRegex(ValueError, 'unsafe'):
                policy.verify_runtime({**report, **changes})


if __name__ == '__main__':
    unittest.main()
