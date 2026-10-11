# Staging release acceptance — 2026-10-11

The canonical protected application is deployed to staging and running normally.
Actual writer drain, native isolated recovery, off-host transfer, intentional
health failure and successful return have passed. This report supersedes the
pending staging items in the October 9 reports while preserving their historical
evidence. It does not authorise production deployment or claim mandatory DPP
acceptance. No paid service, push, production change, new Fortnox authorization
or Support message was performed during this continuation.

## Active application and final state

- Commit: `fab8ee1ea47fd8f52b39884a3149b826c0dcb51e`, parent `3e6ae56ba20585b9fd17a82e1b94967e39467f02`.
- Archive: `nordipass-20261010-fab8ee1ea47f.tar.gz`, 9,990,513 bytes; SHA-256 `62e311429dc0d16ac48840b05da181e9a28218d349e76e0cebbe712c765fa7f3`.
- Current: `/home/codecraf/nordipass-staging/releases/staging-20261010-fab8ee1ea47f`.
- First fresh-process acceptance: October 10, 00:23:17 UTC. Final acceptance after
  recovery: October 11, 07:06:47 UTC. Writers are resumed; `/up` and `/ready` are
  HTTP 200, schema verification exits 0 and both October 9 migrations are applied.
- Five companies have complete numeric capacity counters, with no missing rows,
  NULL counters or drift. Registry preparation rows remain zero.
- At 07:07:12 UTC, scheduler/worker heartbeat ages were 10/60 seconds and fresh.
  Failed jobs, reserved jobs, database events, other database connections and
  scoped CLI writers were zero. One unreserved pending job remains. This is a
  point-in-time observation of bounded cron processing, not a persistent worker.

The archive was built from exact committed Git blobs with 1,067 source/build
inputs and 89 locked production Composer packages. Secrets, runtime databases,
development dependencies and node_modules are excluded. Its actual server
roundtrip passed SHA-256 verification. The initial parent-process deployment
summary used a cached PHP `realpath(current)` and reported the preceding release;
independent fresh-process acceptance confirms the actual activated release.

## Recovery and failure evidence

The explicitly approved disposable database `codecraf_np_boundary_20261009`
received only the 11 approved database-scoped grants for the staging user.
All live staging backups were restored there, comparing every table row,
trigger and all four persistent file stores, with schema and read-only capacity
validation. Previously verified fixtures were reused only after checking their
owner proof, complete content and retained backup checksums. Main staging data
was not restored or replaced.

Three actual boundary backups passed complete native recovery and real
server-to-local-to-server transfer with local size/SHA-256 checks:

| Backup UUID | Source at backup | Pause generation |
| --- | --- | --- |
| `164c25ed-095a-4225-b526-bb2541aa99ff` | preceding staging release, before canonical deployment | `1791590927233778397` |
| `067000cb-bd08-4b20-8645-8841bc452903` | canonical `staging-20261010-fab8ee1ea47f` | `1791591916081539084` |
| `2d008f85-02e1-46ac-bccb-c3bef159ce16` | compatible `recovery-20261010-fab8ee1ea47f` | `1791702167297454208` |

Compressed backups remain private acceptance artifacts under
`storage/framework/release-20261010/offhost-backups/` and on the server; they are
not documentation or commit inputs. Sanitized proofs and receipts are retained
in the linked evidence directory.

At October 11, 07:02:47 UTC, the real rollback command had activated a separately
verified extraction of the same protected artifact, then failed its intentionally
incorrect staging health URL with exit 5. Its failure trap re-paused writers and
verified the new drain generation: zero scoped CLI writers, other database
connections, database events and reserved jobs. No automatic database rollback
was attempted. The new pause required a new backup and actual off-host transfer;
the previous receipt was not reused.

At 07:06:14 UTC, the normal health URL allowed successful return to the primary
protected release with exit 0 and writers resumed. Both directory targets contain
the same application artifact. This proves actual activation/failure/resume
handling and compatible same-version recovery. It does **not** prove rollback to
an older application version or schema reversal. The preceding source lacks the
new Fortnox guard and was deliberately not activated as a recovery target.

## Causes found and corrections

1. Two pinned cron digests contained 65 hexadecimal characters because of
   transcription errors. Actual cron scripts had not changed. Correct 64-character
   digests are now validated, and a changed writer script is rejected.
2. The backup checker treated a valid lazy NULL capacity counter as corruption and
   called reconciliation, which can append audit rows even without repair. The
   new pure helper accepts unknown counters on existing rows, rejects missing or
   incorrect numeric counters, and checks that restore validation changes no rows
   or triggers. Deployment still initializes counters under a verified drain.
   The failed isolated attempt and its known extra fixture audit row were retained
   diagnostically and reviewed before clearing that disposable fixture.
3. An LSAPI prefork parent was incorrectly included among possible legacy request
   executors. Read-only kernel observations plus actual gated child requests
   established its role. Only PID 2924994 / start tick 252578698 was reclassified;
   no process termination, fabricated admission marker or account-wide restart
   was used. Other unknown legacy processes still block drain.
4. The representative supplier MySQL dump could not execute 14 `REGEXP_LIKE`
   CHECK expressions on target MariaDB. An explicit test-only derivative converts
   those whitelisted ASCII checks to `REGEXP` and removes 30 structured DEFINER
   headers. Reverse transformation reconstructs the source SQL byte-for-byte;
   business rows and trigger logic are unchanged. Its target acceptance passed
   210 checks over 80 tables, 231,182 rows, 30 triggers and four stores. This is
   qualified cross-engine fixture recovery, not proof that the original dump works
   unchanged. Native same-server staging backups require no such conversion.

The representative file fixture contains four import files (4,246,193 bytes)
and one document (124,326 bytes). Catalog media and passport assets are empty.
Their empty-store checks passed, but high-volume media/asset recovery is not
demonstrated; it needs a representative populated fixture if in the release scope.

The earlier failed supplier restore remains a preserved diagnostic fixture in
`codecraf_np_supplier_20261009` (13 tables / 10 triggers). It is separate from
the accepted boundary fixture and live staging database.

## Verification and source provenance

The full application suite previously completed with 2,975 tests / 14,153
assertions and exit 0 on the application source included in this commit. Pint,
PHPStan with zero errors, frontend build, Composer validation/advisory audit and
production npm audit passed. The full npm audit retains the five previously
accepted high development-tool findings; its exit 1 is not reported as clean.
Duplicate PHP extension startup warnings remain pre-existing environment noise.

The later staging hook fixes are a separately verified operating package, v6,
SHA-256 `c56658fb5a7d2a499802be5b835187a6b0d527d5eb6247ca1d865fdd5ed141c9`.
All 24 Linux tests passed on native PHP/flock with no skips; PHP/bash syntax and
focused formatting checks passed. The installed source digests match this
working tree. Hooks, their regression tests, this report and README changes remain
uncommitted; no additional commit or push was made in this continuation.
They are outside the immutable application archive. Historical October 9 package
versions and failed attempts remain retained.

Actual local frontend verification used Node 24.15.0. The archive generator's
`node_version: 22.x` is hard-coded and must not be read as observed build-runtime
provenance. PHP build/target versions are 8.4.21 / 8.4.26. A production candidate
must correct that Node provenance and, if Node 22 is required, verify that build
explicitly; this report does not claim a Node 22 run.

## Fortnox and remaining release gates

The full account guard is now deployed on staging. Pure server-side checks block
production subscription `1853390`, an unknown identity and unrelated accounts;
test subscription `1854146` passes the identity check. New real OAuth remains
disabled. Existing Fortnox connection metadata is unchanged. No external request
was performed by this acceptance check. The earlier October 9 authenticated
read of 100 test articles remains evidence of existing test API access, not a
fresh authorization acceptance.

October 4 license activation remains unexplained: Support confirmed the date of
addition but did not identify the time, actor, ordering channel or confirmation
action. OAuth or token refresh is not established as the purchase mechanism.
The [Support request](2026_10_09_FORTNOX_SUPPORT_REQUEST.md) remains a draft.
See the [audit](2026_10_09_FORTNOX_OAUTH_AUDIT.md) for PKCE/endpoint-hardening
follow-up and incident evidence.

Before a production release, fix reproducible build provenance, review/fixate the
separate hook package, and qualify a guarded older-version recovery baseline
against the current schema. Production deployment itself is not authorized.
If fresh Fortnox connection is in scope, it still needs verified approved client
settings, confirmed test-only account access and explicit authorization to run
the real flow; the guard cannot reverse a purchase made inside Fortnox before
token exchange.

Mandatory sector/battery DPP claims remain gated on real product/legal evidence,
verified economic-operator status and accepted EU Registry access/integration.
No real battery or verified business was supplied. EU Login/passkey and installed
technical migrations do not complete those requirements. The dated Registry
guidance in the [October 9 continuation](2026_10_09_RELEASE_CONTINUATION.md)
is retained as dated evidence, not a new observation of Registry availability.

## Retained evidence

The [evidence manifest](evidence/2026_10_11_staging_release/manifest.json) records
local SHA-256 and source identities. The [server acceptance bundle](evidence/2026_10_11_staging_release/target-acceptance.json)
contains original/final deployment checks, native hook tests, actual failed-health
and return results, latest backup proof, transport receipt, LSAPI observations,
representative recovery and fresh cron state. Earlier backup proofs/receipts,
archive roundtrip and cross-engine derivation are retained beside it. Evidence
contains counts/checksums only, without raw SQL, private keys, tokens or logs.
