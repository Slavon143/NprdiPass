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

- `verify-release ROOT RELEASE_DIR`: read-only pre-activation check. Require the
  reviewed Fortnox source fingerprints and actual staging configuration; reject
  production/unknown/wrong tenants and enabled new OAuth. It does not perform
  external requests, token exchange, renewal or connection changes. The generic
  rollback command invokes it before imposing a pause. Keep `release-policy.json`
  bound to the reviewed source; a future safety-layer change needs review and new
  fingerprints. This hook is restricted to staging, not a production policy.
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

Backup validation uses the read-only `capacity-state.php` helper and tenant-scoped
SQL counts. An existing capacity row with a lazy NULL counter is valid unknown
state; a missing row, malformed count or incorrect numeric count fails validation.
Do not replace this check with `catalog:capacity-reconcile`: even inspection mode
can append audit rows for drift. Deployment still reconciles counters under the
verified drain before activation. Completed restore validation must preserve all
restored rows and triggers byte-for-byte. Failed attempts retain their workspace;
new attempts use a separate random suffix.

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

## Target acceptance and LSAPI classification

The October 10 observations classified the exact PID 2924994 / start tick
252578698 as an LSAPI prefork parent. Kernel observations showed one listening
socket, the parent's select-loop signature and actual child processes passing
the staging gate. The parent never executed a PHP request. Its original baseline
was retained; only that exact non-request identity was excluded from the legacy
request barrier. No process was killed, no seen marker fabricated and no shared
PHP service restarted. Unclassified request processes still block drain.

Twenty-four native Linux/PHP/flock regressions passed with no skips. Actual
staging deployment, complete isolated restoration and real off-host transport
passed. On October 11, an intentionally failed health check after activation
re-paused and verified writers; a fresh backup/transport roundtrip then allowed
successful return and resume. The recovery target is another extraction of the
same protected artifact. This proves that mechanism, not rollback to an older
source version. See the [October 11 acceptance report](../../../docs/releases/2026_10_11_STAGING_RELEASE_ACCEPTANCE.md)
for evidence, source/package identities and remaining release gates.
