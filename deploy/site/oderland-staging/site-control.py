#!/opt/alt/python312/bin/python3
"""Bounded, fail-closed writer hooks for the existing Oderland staging only."""
import argparse
from contextlib import ExitStack
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time
import urllib.error
import urllib.request

BASE = Path('/home/codecraf/nordipass-staging')
PHP = '/opt/alt/php84/usr/bin/php'
HEALTH = 'https://staging.nordipass.nu.codecrafters.nu'
HERE = Path(__file__).resolve().parent

def timestamp():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()

def write_json(path, value):
    temporary = path.with_name(path.name + '.new-' + str(os.getpid()))
    with temporary.open('x') as stream:
        json.dump(value, stream, indent=2)
    temporary.chmod(0o600)
    os.replace(temporary, path)

def process_start(pid):
    try:
        text = Path('/proc', str(pid), 'stat').read_text()
        return text[text.rindex(')') + 1:].split()[19]
    except FileNotFoundError:
        return None

def legacy_pending(installation, directory):
    pending = []
    for identity in installation['legacy_web_processes']:
        if process_start(identity['pid']) != identity['start']:
            continue
        if not (directory/'seen'/f"{identity['pid']}-{identity['start']}").is_file():
            pending.append(identity['pid'])
    return pending

def state():
    result = subprocess.run([PHP, str(HERE/'inspect-state.php')], capture_output=True, text=True, timeout=30)
    if result.returncode != 0:
        raise RuntimeError('Staging database identity/inspection failed; no resume allowed')
    return json.loads(result.stdout)

def validate(root):
    if Path(root) != BASE or BASE.resolve() != BASE or not BASE.is_dir():
        raise RuntimeError('This hook is limited to the explicit staging root')
    current = (BASE/'current').resolve(strict=True)
    if current.parent != BASE/'releases' or not (BASE/'current').is_symlink():
        raise RuntimeError('Invalid current release')
    shared = BASE/'shared'
    if (current/'.env').resolve(strict=True) != shared/'.env':
        raise RuntimeError('Unexpected shared environment')
    directory = shared/'storage/framework/nordipass-writer-gate'
    installation = json.loads((directory/'installation.json').read_text())
    gate = shared/'storage/framework/maintenance.php'
    if not installation.get('installed') or gate.is_symlink() or hashlib.sha256(gate.read_bytes()).hexdigest() != installation['gate_sha256']:
        raise RuntimeError('Verified HTTP admission gate is unavailable')
    entry = (current/'public/index.php').read_text()
    if 'storage/framework/maintenance.php' not in entry or 'require $maintenance' not in entry:
        raise RuntimeError('Current front controller does not load the gate')
    for kind, expected in (
        ('scheduler', 'fc2fc01cfdfcd6e8c4f71572ee99820e911e979e7bea16f1bf5fba90dd4887023'),
        ('worker', '8292dd26e91e592ca9366414bff6f0f2df2ab3514c1241337531cf61223869ed7'),
    ):
        actual = hashlib.sha256((BASE/'deploy/site'/f'{kind}.sh').read_bytes()).hexdigest()
        if actual != expected:
            raise RuntimeError('Cron entry script changed; writer inventory must be reviewed')
    return shared, directory, installation

def paused_http():
    try:
        with urllib.request.urlopen(HEALTH+'/up', timeout=10) as response:
            return False
    except urllib.error.HTTPError as error:
        return error.code == 503 and error.headers.get('X-NordiPass-Writer-Gate') == 'v1'

def lock_all(shared, directory, stack, timeout=30):
    for path in (shared/'scheduler.lock', shared/'worker.lock', directory/'http.lock'):
        stream = stack.enter_context(path.open('r+'))
        deadline = time.monotonic() + timeout
        while True:
            try:
                fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise RuntimeError('A scheduler/job/request still holds a writer lock')
                time.sleep(0.1)

def verify(shared, directory, installation):
    marker = shared/'writers-paused'
    if not marker.is_file() or marker.is_symlink():
        raise RuntimeError('Writer pause marker absent')
    pause = json.loads(marker.read_text())
    if pause.get('kind') != 'nordipass-staging-writer-pause-v1':
        raise RuntimeError('Unrecognized pause marker; operator action required')
    with ExitStack() as stack:
        lock_all(shared, directory, stack)
        pending = legacy_pending(installation, directory)
        if pending:
            raise RuntimeError('Pre-install web processes not yet seen through the gate: '+','.join(map(str, pending)))
        if not paused_http():
            raise RuntimeError('HTTP startup has not been proved blocked')
        observed = state()
        if any(observed[key] != 0 for key in ('other_database_connections', 'database_events', 'reserved_jobs', 'scoped_cli_writers')):
            raise RuntimeError('Database writers/events/reserved jobs remain')
        report = {'kind': 'verified_staging_writer_drain', 'checked_at': timestamp(), 'pause_id': pause['pause_id'],
                  'http_and_cron_locks_exclusive': True, 'legacy_web_processes_pending': [], 'state': observed}
        write_json(directory/'drain.json', report)
        return report

def pause(shared, directory, installation):
    marker = shared/'writers-paused'
    if marker.exists():
        return verify(shared, directory, installation)
    if legacy_pending(installation, directory):
        raise RuntimeError('Pre-install web processes have not cleared the admission barrier; no pause imposed')
    # Validate database/environment before imposing any temporary pause.
    state()
    write_json(marker, {'kind': 'nordipass-staging-writer-pause-v1', 'pause_id': str(time.time_ns()), 'created_at': timestamp()})
    # Every error preserves the marker. The operator explicitly resumes after review.
    return verify(shared, directory, installation)

def resume(shared, directory, installation):
    report = verify(shared, directory, installation)
    marker = shared/'writers-paused'
    pause_record = json.loads(marker.read_text())
    if pause_record['pause_id'] != report['pause_id']:
        raise RuntimeError('Pause generation changed')
    marker.unlink()
    # New CLI processes load current on each cron invocation; no daemon is assumed.
    try:
        for kind in ('scheduler', 'worker'):
            log = directory/f'resume-{kind}-private.log'
            with log.open('ab') as stream:
                result = subprocess.run(['flock', '-w', '5', str(shared/f'{kind}.lock'),
                                         str(BASE/'deploy/site'/f'{kind}.sh')], stdout=stream, stderr=stream, timeout=360)
            log.chmod(0o600)
            if result.returncode != 0:
                raise RuntimeError('Fresh cron entry failed')
        for endpoint in ('up', 'ready'):
            with urllib.request.urlopen(HEALTH+'/'+endpoint, timeout=10) as response:
                if response.status != 200:
                    raise RuntimeError('Health failed')
        result = {'kind': 'staging_writers_resumed', 'checked_at': timestamp(), 'pause_id': report['pause_id'], 'state': state()}
        write_json(directory/'resume.json', result)
    except BaseException:
        # Restore admission blocking immediately, including timeout/inspection
        # errors. Re-pausing must not depend on the failed database/HTTP probe.
        if not marker.exists():
            write_json(marker, pause_record)
        raise
    return result

def backup(shared, directory, installation):
    drain = verify(shared, directory, installation)
    # Backup/restore is a controlled maintenance writer and holds the same locks.
    with ExitStack() as stack:
        lock_all(shared, directory, stack)
        proof_path = directory/'backup-proof.json'
        proof = json.loads(proof_path.read_text()) if proof_path.is_file() else {}
        if proof.get('pause_id') != drain['pause_id'] or proof.get('completed') is not True:
            log = directory/'backup-restore-private.log'
            with log.open('ab') as stream:
                result = subprocess.run([PHP, str(HERE/'boundary-backup.php'), drain['pause_id']],
                                        stdout=stream, stderr=stream, timeout=600)
            log.chmod(0o600)
            if result.returncode != 0:
                raise RuntimeError('Post-drain backup/isolated restore failed; writers remain paused')
            proof = json.loads(proof_path.read_text())
        if proof.get('pause_id') != drain['pause_id'] or proof.get('completed') is not True:
            raise RuntimeError('Backup proof does not match the current writer boundary')
        if proof.get('source_release') != drain['state']['release']:
            raise RuntimeError('Current release differs from the post-drain backup source')
        transport_path = directory/f"backup-offhost-{drain['pause_id']}.json"
        if not transport_path.is_file():
            raise RuntimeError('Off-host backup verification still required; writers remain paused')
        transport = json.loads(transport_path.read_text())
        if (transport.get('off_host_verified') is not True or transport.get('pause_id') != drain['pause_id']
                or transport.get('backup_id') != proof['backup_id']
                or transport.get('artifacts') != proof['retained_artifacts']):
            raise RuntimeError('Off-host acceptance does not match this backup boundary')
        for artifact in proof['retained_artifacts']:
            source = Path(artifact['path'])
            returned = source.parent/'offhost-return'/source.name
            if (not source.is_file() or not returned.is_file() or source.is_symlink() or returned.is_symlink()
                    or source.stat().st_size != artifact['bytes'] or returned.stat().st_size != artifact['bytes']
                    or hashlib.sha256(source.read_bytes()).hexdigest() != artifact['sha256']
                    or hashlib.sha256(returned.read_bytes()).hexdigest() != artifact['sha256']):
                raise RuntimeError('Retained/returned backup artifact integrity failed')
    verify(shared, directory, installation)
    return proof

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('action', choices=('pause', 'verify', 'resume', 'backup'))
    parser.add_argument('root')
    args = parser.parse_args()
    os.umask(0o077)
    shared, directory, installation = validate(args.root)
    print(json.dumps(globals()[args.action](shared, directory, installation), indent=2))

if __name__ == '__main__':
    main()
