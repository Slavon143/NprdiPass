# Oderland staging writer hooks

These hooks are restricted to `/home/codecraf/nordipass-staging`, PHP 8.4,
Python 3.12 and the existing bounded cron wrappers. They do not implement
Supervisor or persistent Redis workers. They reject other roots, changed cron
wrappers, an unexpected database and an unverified HTTP admission gate.

## Installation and admission

The runtime `shared/storage/framework/maintenance.php` is an HTTP admission
gate, loaded by the standard Laravel front controller before bootstrap. Its
shared `flock` lease lasts through PHP shutdown. A pause marker rejects new
requests with HTTP 503 and `X-NordiPass-Writer-Gate: v1`; otherwise requests
continue normally. Do not run `artisan down` or `artisan up` while this gate is
installed: those commands replace/remove its runtime file.

The private gate directory records its verified SHA-256 and the identities of
web processes existing before installation. A process must either disappear
(including PID reuse with a different start tick) or be observed through the
gate before it can be considered drained. An unclassified process is not
terminated or assumed idle. The pause hook refuses before imposing downtime
when this barrier has not cleared.

Installation must verify a tested source package, PHP/bash syntax, current
staging identity and hashes of any existing hooks before replacing them. Keep
the gate directory and hook directory private (0700), PHP/Python files 0600 and
shell entry points 0700. Use LF line endings on Linux.

## Hooks

- `pause-writers ROOT`: validate identity, create an owned generation marker,
  wait for HTTP/scheduler/worker exclusive locks and inspect database writers.
- `verify-writers-stopped ROOT`: require the same marker, reject pending legacy
  processes, database events/connections, scoped CLI writers and reserved jobs;
  prove HTTP startup is blocked and record the drain generation.
- `verify-backup ROOT`: retain those locks while making a complete native
  database/files backup and restoring it into the explicitly pre-created
  `codecraf_np_boundary_20261009`. Compare every row, trigger and persistent
  store, verify schema/capacity, and require matching off-host transport proof.
- `resume-writers ROOT`: verify the boundary, remove only its owned marker,
  invoke fresh bounded cron entry points and check `/up` and `/ready`. Restore
  admission blocking immediately on failure, including subprocess timeout.

Errors preserve the pause. They do not automatically revert schema, release or
database. Review the current source/schema and writers before explicitly
resuming. These hooks are not release acceptance until the real boundary,
backup/restore, deployment and rollback scenarios have passed.

## Isolated backup and transport

The boundary database must be explicitly approved as a disposable restore
fixture, created empty, and granted only database-scoped privileges to the
existing staging user. No database is created automatically. A later reuse
clears only the previously verified fixture: its full contents and triggers
must still match the retained owner proof, and its previous backup artifacts
must still pass checksums. Changed or unrelated target data is rejected.

The first backup verification attempt creates the native backup and restore
proof, then fails closed if off-host acceptance is absent. With writers still
paused, download both artifacts to a separate machine, verify their bytes and
SHA-256 locally, and return the same verified files to `offhost-return/` beside
each retained original artifact. Retain the local copy.

After this actual transfer and local verification, record private
`nordipass-writer-gate/backup-offhost-<pause_id>.json`:

```json
{
  "off_host_verified": true,
  "pause_id": "the actual drain generation",
  "backup_id": "the actual native backup UUID",
  "artifacts": "the exact retained_artifacts array from backup-proof.json"
}
```

`artifacts` must be an array, not the explanatory string shown above. The
receipt is an operator/CI attestation of the off-host step; it must not be
generated from server-only checks. The hook checks the pause, backup UUID,
source release, artifact metadata and actual original/returned bytes. It
reuses the completed backup for that same pause, preserving the original
proof. A receipt alone or a restore into the neighbouring database is
insufficient. Missing/corrupt transport evidence keeps deployment blocked.

## Verification

Run `tests/Release/test_staging_writer_hooks.py` on Linux with the pinned
native PHP. Its temporary fixtures exercise actual PHP/flock shutdown and
request admission, plus lifecycle error handling. They have no application
database or external HTTP access and do not substitute for a real staging
deployment rehearsal.

## Pending provider clarification (draft only, not sent)

For account `codecraf`, the staging site is
`staging.nordipass.nu.codecrafters.nu`. At 2026-10-09 14:48 UTC an existing
PHP 8.4 LSAPI process, PID 2924994 with Linux start tick 252578698, had not
passed through the new staging admission gate. Its visible working-directory,
arguments and file-descriptor metadata did not establish application ownership.

Ask Oderland to identify which application/pool owns that exact process and
whether staging has a dedicated LSAPI pool that can be drained or recycled
without affecting other domains on the account. Request the supported
application-specific operation and how to verify that no pre-gate request
remains. This draft does not authorize terminating the process or restarting
account-wide PHP services. Until ownership/drain is established, preserve the
admission barrier and leave the live site running normally.
