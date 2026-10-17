#!/opt/alt/python312/bin/python3
"""Read-only staging policy: retain the reviewed Fortnox safety boundary."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import subprocess

BASE = Path('/home/codecraf/nordipass-staging')
HERE = Path(__file__).resolve().parent
PHP = '/opt/alt/php84/usr/bin/php'


def verify_source(root, target, policy):
    root, target = Path(root), Path(target)
    if root != BASE or root.resolve(strict=True) != BASE or not root.is_dir():
        raise ValueError('This release policy is limited to the explicit staging root')
    if target.is_symlink() or target.resolve(strict=True) != target or target.parent != BASE/'releases':
        raise ValueError('Target must be an owned staging release directory')
    if not (target/'.env').is_symlink() or (target/'.env').resolve(strict=True) != BASE/'shared/.env':
        raise ValueError('Target must use the verified staging environment')
    if policy.get('kind') != 'reviewed_fortnox_nonproduction_source' or not policy.get('files'):
        raise ValueError('Missing reviewed integration policy')
    for name, expected in policy['files'].items():
        path = target/name
        if (PurePosixPath(name).is_absolute() or any(part in ('.', '..') for part in name.split('/'))
                or len(expected) != 64 or any(c not in '0123456789abcdef' for c in expected)):
            raise ValueError('Invalid reviewed integration fingerprint')
        if not path.is_file() or path.is_symlink() or path.resolve() != path:
            raise ValueError('Reviewed Fortnox safety source is missing')
        if hashlib.sha256(path.read_bytes()).hexdigest() != expected:
            raise ValueError('Fortnox safety source changed; explicit review is required')
    return target


def verify_runtime(report):
    expected = {'1853390': 'blocked', '1854146': 'accepted', 'unknown': 'blocked', '9999999': 'blocked'}
    oauth_enabled = report.get('oauth_enabled')
    authorization = 'accepted' if oauth_enabled is True else 'blocked'
    if (report.get('environment') != 'staging' or type(oauth_enabled) is not bool
            or report.get('expected_tenant') != '1854146' or report.get('tenant_outcomes') != expected
            or report.get('new_authorization') != authorization or report.get('no_external_requests_performed') is not True
            or (oauth_enabled and (report.get('provider') != 'http'
                or report.get('test_client_verified') is not True
                or report.get('client_configured') is not True or report.get('secret_configured') is not True))):
        raise ValueError('Target staging integration configuration is unsafe')
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--app-root', required=True)
    parser.add_argument('--release-dir', required=True)
    args = parser.parse_args()
    try:
        policy = json.loads((HERE/'release-policy.json').read_text())
        target = verify_source(args.app_root, args.release_dir, policy)
        result = subprocess.run([PHP, str(HERE/'release-policy-state.php'), str(target)],
                                capture_output=True, text=True, timeout=30)
        if result.returncode != 0:
            raise ValueError('Target integration inspection failed')
        report = verify_runtime(json.loads(result.stdout))
        print(json.dumps({'compatible': True, 'release': target.name, 'policy': policy['kind'], 'runtime': report}))
        return 0
    except (ValueError, OSError, subprocess.SubprocessError):
        # Never expose PHP output, configuration values or exception diagnostics.
        print(json.dumps({'compatible': False, 'reason': 'Reviewed staging integration safety policy rejected this target'}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
