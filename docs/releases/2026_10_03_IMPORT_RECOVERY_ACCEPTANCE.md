# Import recovery and acceptance preparation, 2026-10-03

This continues `2026_10_03_XLSX_AND_OPERATIONS.md` on the existing working tree.
It adds a confirmed Job Center recovery fix, real worker and local HTTP evidence,
target PHP 8.4 verification, review units and a staging acceptance procedure.
It is not a production deployment or a completed external staging acceptance.

## Confirmed fixes

After an abandoned import worker failed, API restart completed the import but
JobStatus retained `failure_code`, `failure_message` and `failed_at`. Job Center
rendered the old message despite success. The regression reproduced this before
the fix. `JobStatusTracker::markRunning()` now clears terminal attempt metadata;
`markSucceeded()` clears failure metadata. Progress, the first start timestamp
and failed_jobs evidence remain available.

The CI backend runs directly on ubuntu-latest and publishes the MySQL service's
port. DB_HOST=mysql assumed service-name DNS provided to container jobs; the
runner must use the published localhost port, as described in the
[GitHub service-container documentation](https://docs.github.com/en/actions/tutorials/use-containerized-services/use-docker-service-containers).
CI now uses 127.0.0.1. CI/release
PHP 8.4 setup explicitly includes zip, xmlreader and xmlwriter with existing
required extensions. Both workflow YAML files parse. These working-tree workflow
changes have not run on GitHub Actions; metadata of older failed workflows is not
evidence of their failure cause.

## Recovery evidence

`ImportWorkerCrashRecoveryTest` launches actual child queue workers and exits:

| Boundary | Exit | Committed rows before recovery |
|---|---|---|
| During a chunk before commit | 81 | 0 |
| Immediately after commit | 82 | 2 |
| After continuation enqueue | 83 | 2 |

Each case expires only its owned abandoned reservation, observes failure at the
real worker attempt limit, restarts through the tenant-authorized API, and drains
workers including duplicate continuation. Exactly five products, variants and
creation audit rows result. No rows are skipped and failed_jobs evidence remains.
The API and Job Center show success without stale failure fields.

A separate PHP 8.4.26 loopback TCP HTTP acceptance exercised multipart upload,
foreign-tenant 404, preview, queued apply, exit 82, checkpoint preservation and
API restart with actual workers on another owned schema. It produced exact five
row/product/variant/audit counts and request IDs. Its server was explicitly stopped
after sandbox taskkill could not finish cleanup; the fixture then revoked its
temporary tokens and exited zero. The final repeat used a directly owned native
process handle, completed server/token cleanup automatically, and exited zero.
It used synthetic files and a database queue,
not a site's supplier corpus, reverse proxy, Linux supervisor or Redis.

## Verification

- PHP 8.5.2 full suite: 2,526 tests / 11,000 assertions, zero failures, exit zero.
- PHP 8.4.26 focused recovery/Job Center: 8 tests / 167 assertions, exit zero.
- PHP 8.4.26 full suite: 2,526 tests / 11,000 assertions, zero failures, exit zero.
- All 25 changed PHP files pass Pint on PHP 8.4; PHPStan reports zero errors.
- PHP 8.4 XLSX source verification: all 100,000 rows match exact expected values,
  41.847 seconds, 30 MiB PHP peak under memory_limit=128M. Output SHA256 equals
  `f4b7b8b2b4deae9fc35f1081edc889da65b3b2d4c9b38061824f09b04944602c`, the same
  as the prior PHP 8.5 run. This measures reading, not full product apply throughput.
- Portable PHP archive SHA256 was checked against the
  [official PHP Windows release metadata](https://downloads.php.net/~windows/releases/releases.json).
  The system runtime and global PATH were not modified.

Tests force an owned MySQL `_testing` schema and isolated storage/cache paths;
external integrations use fakes. Ordinary local and production databases were
not used. After all processes completed, the owned schemas
`nordipass_audit_fixes_20261002_testing` and
`nordipass_stage_profile_20261003_testing` and their isolated fixture storage were
removed. Logs, JUnit XML, source XLSX files, profiles and the portable runtime
remain in the evidence directory. The 1,292-file executable/config/test/resource
manifest was rechecked unchanged after verification (`acceptance-summary.json`).

## Measured write debt

A separate mapped 500-row import produced 500 products/variants, 1,000 attribute
values and 500 category/selection links, without errors. Preview: 0.863 seconds,
20 queries. Apply: 14.392 seconds, 23,528 queries, 7,809.36 ms SQL time, two chunks,
7.228 seconds maximum chunk, 50 MiB PHP peak.

The normalized query profile locates repeated membership reads (2,004) in
`CurrentMembership.php:16`, company reads (2,000) in `ProductAction.php:34`, and
product/default variant reloads (1,500 each) in `ProductAggregateCreator.php:66`.
Grouping records the first representative application callsite. Fresh option
validation (1,000 option counts), 1,002 audit inserts and capacity serialization
are correctness boundaries, not queries to delete indiscriminately. No permission
cache or capacity counter was introduced. This measurement is not a 100k SLA.

## Review package and remaining dependencies

The nine review units and draft PR descriptions are in `REVIEW_PACKAGE.md` in the
evidence directory. Patches 01–07 plus prior documentation apply to their saved
continuation baseline; new units 08/09 pass forward and reverse checks on their
own saved baseline. New-file patch metadata was corrected. These are not
independent changes against remote master: most depend on earlier uncommitted
remediation, and only 04/06 apply literally to the local HEAD. Literal application
is not semantic certification. No commit, push, PR or deployment was performed.

Staging discovery inspected repository documentation/settings, relevant local
supplier-file locations and authenticated GitHub metadata. Documentation supplies
only staging.example.com; local APP_URL is nordipass.test. GitHub returned zero
environments, repository variables and deployment records. No usable staging or
supplier corpus was discovered. This does not prove none exists externally.

Remaining work is ordered as follows:

1. Resolve the reviewed common Git base before opening the small PRs; the observed
   remote master differs from local HEAD and the working tree includes older work.
2. Obtain or establish actual staging and an anonymized representative supplier
   corpus, then execute `docs/testing/IMPORT_WORKER_STAGING_ACCEPTANCE.md`.
3. Decide durable executable DPP release retention and legacy-draft policy before
   historical replay changes. Seven-day CI artifacts and five retained deploy
   releases do not establish long-term replay; do not invent historical evidence.
4. Reduce measured repeated reads only with tenant/authorization/concurrency
   regressions; finite capacity remains linear.
5. Verify actual receiver idempotency, telemetry backend, scheduler and worker
   supervision. Resolver histogram remains off by default.

Evidence directory:
`C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/staging-acceptance-20261003/`.
