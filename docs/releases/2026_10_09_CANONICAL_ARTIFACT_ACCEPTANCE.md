# Canonical executable acceptance — 2026-10-09

This evidence was recorded after the source commit and archive were fixed. It
does not change the executable and is retained as post-build documentation.
No push, paid purchase or production deployment was performed.

## Fixed source and quality

The user explicitly authorised one local commit of the 563 proposed source,
test, configuration and documentation files, including accumulated prior work,
without push. Final commit: `3e6ae56ba20585b9fd17a82e1b94967e39467f02`.
The same unpublished commit was updated to include the discovered archive
database-exclusion correction. Unrelated existing demo deletions and runtime
artifacts were excluded and preserved.

The complete PHP suite passed: 2934 tests / 14017 assertions, exit 0,
3795720 ms. Pint, PHPStan, 12 JavaScript regressions, production frontend build,
Composer validation/audit and production npm audit pass. Full npm audit retains
only the previously accepted five high findings in the braces development chain.
After that full application gate, the packaging-only correction passed 15 Python
archive regressions, its PHP wrapper (one test / five assertions), and all 23
actual-Linux archive/lifecycle fixture tests. The latter use fake PHP, HTTP and
site hooks; actual site deployment and writer control remain separate gates.

## Archive and provenance

Archive: `nordipass-20261009-3e6ae56ba205.tar.gz`.

- Size: 9989713 bytes.
- SHA-256: `c8e6e0398d9f5e3a5746460ca5b256eabb20c66aa28ec060af8b0cd84914e20e`.
- Source/build input count: 1065 (1062 committed inputs and three verified build outputs).
- Source/build input SHA-256: `b97b8510bb914b560e1e0d3cbf97bfcf0b46e124dd796a448c0694dc163c34b9`.
- Locked production dependencies: 89 packages, no development dependencies.
- Build PHP: 8.4.21; target PHP: 8.4.26.

Application inputs were read from the actual Git commit blobs. Nineteen files
had only Git's configured CRLF-to-LF normalization relative to the tested scratch
copy; all other committed input bytes agreed. The three generated assets match
the previously verified production build. The ignored 98304-byte local SQLite
copy found in the initial scratch candidate is excluded. The artifact classifier
also rejects SQLite/DB snapshots and WAL/SHM/journal sidecars under database,
including injected archive entries with otherwise matching checksum files.
Original source/runtime data was not removed.

The archive's own FILES.json and RELEASE.json checks pass. Local retention,
platform requirements, package discovery, route cache and view cache pass in
the canonical source copy. An initial Windows sandbox invocation could not
write Laravel's package manifest; the authorised retry outside that restriction
passed. That initial invocation is not passing application evidence.

## Actual target and off-host copy

The target verifies and retains the executable at:
`/home/codecraf/nordipass-staging/artifacts/c8e6e0398d9f5e3a5746460ca5b256eabb20c66aa28ec060af8b0cd84914e20e/`.

It was privately materialised in
`shared/acceptance/canonical-release-20261009-3e6ae56ba205/executable`.
The installed file set matches the retained archive before and after cache
generation. On actual target PHP 8.4.26, package:discover, route:cache and
view:cache all return zero. This copy has no .env or database snapshot, uses
testing/fake providers and does not connect to the staging database. It contains
both October 9 DPP migrations; they were not applied to the active database.
There was no isolated schema check in this executable-only scenario.

A real PSCP download back to a new local directory passed native archive
verification at 12:23:19 UTC: size, SHA-256 and complete release metadata match
the original. Local original, returned copy and target retained executable
remain available. Early SSH transport failures are retained separately rather
than represented as successful transfers.

The active current symlink remained
`staging-source-20261007-3b3dd0fb5f00` before and after target acceptance.
This closes source fixation, canonical archive build/verification and the
archive's target/off-host acceptance. It does not activate the new application.

## Remaining gates

- Representative supplier restore on target MariaDB: the empty isolated
  `codecraf_np_supplier_20261009` and guarded cross-account derivative are ready;
  the prepared 11 database-scoped privileges await the user's pending browser
  action-time confirmation. The original foreign-DEFINER restore helper must
  not be executed there. Larger original transport and full local restores pass.
- Actual HTTP, cron and queue writer pause/drain/resume and post-drain backup
  hooks; canonical application deployment, failed-health handling and compatible
  rollback rehearsal. The hosting policy and fixture tests do not prove drain.
- Installation and acceptance of October 9 DPP changes through that boundary.
- Real legal profile/evidence coverage and authorised EU Registry integration
  for the intended mandatory sector/battery scope. Technical ETIM classification,
  developer EU Login and test data do not close that acceptance.

Primary local evidence is under `storage/framework/release-20261009/`:
`source-commit-result.json`, `approved-source-final.json`,
`committed-candidate-verification.json`, `canonical-release-result.json`,
`target-canonical-release-result.json` and
`canonical-roundtrip-verification.json`. They are acceptance artifacts, not
application payload. See [preceding continuation](2026_10_09_RELEASE_CONTINUATION.md)
for supplier recovery, hosting and Registry qualifications.

## Staging boundary preparation on October 9

At 14:16:21 UTC the standard front-controller maintenance include was used to
install a private HTTP admission gate in shared runtime storage. The active
application source and `current` symlink were not modified. The gate rejects
new requests only while an owned writer-pause marker exists, and holds a shared
file lock through PHP shutdown for admitted requests. Its SHA-256 is
`4abb0baea89286528a17223a8bf86766407d60a073a731ccfc73cc5fdb9b3b66`.

Private staging-specific lifecycle hooks were finalized at 14:48:11 UTC after
18 isolated Linux regression tests passed on actual PHP/flock, with no skips.
The package SHA-256 is
`feff6209083b394f5e4d56b64fa97978fbb0cb16e19d8828e2e6b012944c346b`.
PHP/bash syntax checks passed. Tests cover admitted/paused requests, shutdown
leases, lock/pause races, PID reuse, legacy barriers, failed cron/timeout resume,
wrong backup generations, missing/corrupt off-host transport evidence and
actual heartbeat age rather than long-lived cache expiration.

The hooks require actual HTTP/cron drain, absence of database/events/reserved
jobs/scoped CLI writers, native complete backup, every-row/trigger/file-store
isolated restore, schema/capacity checks and actual off-host copy acceptance.
Database inspection reads only the known heartbeat timestamps directly: it does not
use cache retrieval that can delete expired database cache rows. Failed resume
restores the pause before attempting further inspection. Missing transport
evidence keeps deployment blocked.

The final 14:48:11 UTC target inspection confirms `/up` and `/ready` return 200,
scheduler/worker heartbeat ages are 8/9 seconds, no scoped CLI/database writers,
database events, reserved jobs or failed jobs were observed, and one unreserved
job remains. Writers were not paused and the active release remains October 7.

One pre-installation PHP web process, PID 2924994 / start tick 252578698, remains
unclassified and has not been observed through the admission gate. It is not
assumed idle or terminated: it may serve another application. Until it exits,
passes through the gate, or a provider supplies verified application-specific
process control, the pause hook refuses before imposing downtime. No real
pause/backup/deployment/rollback acceptance is claimed from the isolated tests.

The existing supplier restore grant remains pending action-time confirmation
for its 11 database-scoped privileges. Creation of the separate
`codecraf_np_boundary_20261009` restore database was rejected by automatic
approval review because that exact permanent database was not explicitly
approved. No form value was submitted and that database was not created.
An explicit request is pending; scoped grants and disposable fixture reuse
also need to be confirmed before the real backup helper is executed.

Source and operating instructions are in
[`deploy/site/oderland-staging`](../../deploy/site/oderland-staging/README.md).
Sanitized target evidence is in
[`evidence/2026_10_09_staging_writer_hooks/target-readiness-final.json`](evidence/2026_10_09_staging_writer_hooks/target-readiness-final.json).
The earlier 14:41 inspection recorded cache expiration only and is retained as
intermediate diagnostic evidence, not proof of actual heartbeat age.
The Fortnox guard remains tested local source awaiting the final canonical
release; the temporary staging OAuth-start containment remains in place.
Production connections and new OAuth grants were not changed or initiated.

The final local application suite completed at 15:01 UTC with exit 0:
2975 tests passed, 14153 assertions, duration 3943361 ms. Pint, PHPStan (zero
errors), standalone PHP formatting, frontend production build and strict
Composer validation pass. Composer advisory and production npm audits are
clean. The full npm audit retains exactly the five accepted high dev-chain
findings; their severity, ranges and advisory relationships match the October
8 accepted evidence, with no moderate or critical findings. Its exit 1 is
retained rather than counted as a clean audit. Duplicate PHP extension-load
warnings are existing environment diagnostics; they did not fail the suite.

These checks cover the local Fortnox changes and the separately tested staging
hook sources. A precise 43-file prospective commit list is retained in
`storage/framework/release-closure-20261009/proposed-release-files.json`.
It excludes pre-existing demo deletions, test-output/release-plan changes,
runtime artifacts, credentials and backups. No new commit or archive was
created: the earlier one-commit authorization has already been fulfilled,
and AGENTS.md requires a new explicit instruction before this source fixation.
Canonical artifact acceptance above still refers to commit 3e6ae56; it does
not claim that the newer local patch is present in that archive or on staging.
