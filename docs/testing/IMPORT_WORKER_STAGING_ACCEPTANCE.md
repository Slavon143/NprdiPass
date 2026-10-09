# Import worker acceptance

This acceptance follows the XLSX and operations remediation recorded in
`docs/releases/2026_10_03_XLSX_AND_OPERATIONS.md`. Local verification does not
replace acceptance on a site's actual supplier files, reverse proxy and workers.

## Local evidence

`tests/Concurrency/ImportWorkerCrashRecoveryTest.php` submits an XLSX through the
Laravel API request kernel, previews it, starts apply, and runs real child PHP
processes with the database queue. It exercises abrupt exits before chunk commit,
after chunk commit and after enqueueing continuation. The first case must roll
back; the latter cases must retain the committed checkpoint. The test expires
only its abandoned reservation, observes the terminal failure, restarts through
the authorized API, and drains actual workers, including duplicate continuation.
It checks exact product, variant, record and audit counts, no skipped rows, no
remaining queue rows, retained failed_jobs evidence, and successful Job Center
output without a stale failure message.

The worker fixture accepts scalar tenant/job identifiers, checks their ownership,
requires APP_ENV=testing and a MySQL schema ending in `_testing`, and prevents
external HTTP. Run this destructive database test only on an owned disposable
schema: it uses `migrate:fresh`. Do not point it at a shared staging database.

Use a dedicated PHPUnit configuration forcing every database, storage, cache,
mail and integration setting. Then run:

```powershell
composer exec -- php vendor/pestphp/pest/bin/pest --configuration <isolated-phpunit.xml> tests/Concurrency/ImportWorkerCrashRecoveryTest.php tests/Feature/Notifications/NotificationCenterTest.php
```

This is an API kernel and process test. It does not exercise TCP HTTP, a reverse
proxy, Linux process supervision or Redis reservation behavior.

A separate 2026-10-03 local acceptance used PHP 8.4.26's loopback TCP server,
multipart XLSX upload, foreign-tenant 404, preview, queued apply, abrupt exit after
commit, authorized API restart and actual database workers. Five synthetic rows
produced exactly five products, variants and creation audit rows, with the
two-row checkpoint preserved. Request IDs were present. The owned server was
stopped and its short-lived tokens revoked afterward. This adds actual local HTTP
coverage; it still does not establish the site's proxy/supervisor behavior.

## Staging discovery on 2026-10-03

The repository environment documentation contains a staging.example.com
placeholder. Local APP_URL points at nordipass.test. GitHub repository metadata
returned zero environments, repository variables and deployment records. No
supplier XLSX was found in repository fixtures or the inspected relevant local
file locations. This does not establish that no external staging exists; it
means no usable staging endpoint or supplier corpus was discovered. No deployment,
production migration or external integration was executed.

## Site acceptance sequence

1. Establish an owned staging tenant and access to representative anonymized
   supplier XLSX files. Record each file's SHA256, expected row counts, mappings
   and expected formatted values before import. Include leading-zero identifiers,
   both workbook calendars, dates, formulas, sparse cells, multilingual text and
   selectable attributes. Compare with the supplier's expected values and the
   native formatter; do not approve unknown output merely because it imports.
2. Pause and drain import workers before index DDL. Install the capacity and
   relationship indexes before activating queries that select them. Restart
   workers after deployment. Do not run DDL while imports are processing chunks.
3. Upload, preview and apply through actual HTTP, with the site's proxy limits and
   normal queue supervisor. Verify request IDs, job polling, progress, final row
   totals and tenant ownership. Measure peak PHP/process memory, preview duration,
   maximum chunk duration, query counts and queue lag on representative sizes.
4. Use a dedicated disposable crash-drill tenant/schema to stop a worker at the
   three transaction/continuation boundaries. Verify rollback or checkpoint and
   replay without duplicate products/audits. Observe the site's reservation and
   supervisor behavior; never forcibly expire a shared production reservation.
5. Exercise finite-plan limits, cancellation, changed source file, row validation
   failure and database retry. Check prior checkpoints, error visibility and API
   restart permissions from two tenants. No automatic retry of an ambiguous lost
   commit acknowledgement is allowed.
6. Roll back code before dropping an index selected explicitly by that code.
   Preserve failed_jobs and import/audit evidence. Record the tested build SHA,
   PHP/MySQL/queue versions, command exit codes and file hashes in the release
   evidence. Accept the site only when the actual HTTP/worker checks pass.

## Follow-ups

The complete CSV/Company-wait follow-up is recorded in
`docs/releases/2026_10_03_COMPLETE_IMPORT_LOCK_PROFILE.md`. It measures a complete
1000-row pipeline against 10k/100k existing catalogues, both finite and unlimited,
with the current authorization protocol. It uses manually invoked real job handles
with fake dispatch, and therefore does not close the site's queue/proxy/supervisor
gate. Preserve the separate clean timing and instrumented contention samples.

Admission now reads current override/subscription policy inside its transaction.
Site acceptance must include a committed quota decrease/increase and override
creation/revocation behind an earlier snapshot, plus policy changes competing with
an already admitted chunk. Verify deny/admit decisions, outer rollback and retained
checkpoints. A capacity counter must preserve these policy tests as well as the
existing last-slot and current-cardinality regressions.

Profile mapped writes before reducing repeated membership/company/product reads.
Keep audit, tenant authorization, capacity serialization and fresh option
validation at mutation boundaries. The 500-row profile is a baseline, not a
prediction for 100k products or a justification for caching permissions.

Historical DPP replay still needs a durable executable-release archive and an
explicit retention/legacy-draft policy. Existing historical evidence cannot be
reconstructed by changing readiness results. Resolver telemetry remains optional
and off by default. Real receiver idempotency, scheduler and supervisor checks
remain operational acceptance work.

The transactional counter continuation is documented in
`docs/releases/2026_10_03_CATALOG_CAPACITY_COUNTER.md`. Trigger DDL installation
requires paused catalogue writers; verify all three triggers before resuming.
NULL counters retain scanning admission until explicit backfill. Reconcile after
installation and periodically for drift/new tenants. Include SQL/model soft
delete, unlimited-to-finite changes, backfill/writer concurrency, row/root
rollback, crash recovery and repeated completion in site acceptance. Privileged
TRUNCATE or restore that bypasses triggers requires paused writes, invalidation
and reconciliation. Counter admission retains current policy and Company locks.

The nested creation follow-up is recorded in
`docs/releases/2026_10_03_CATALOG_CREATE_MUTATION_PREFLIGHT.md`. An existing caller
transaction now uses one complete product authorization pass at the action's
savepoint boundary; standalone creation retains its external preflight. Keep
current role, membership, user/company state and bound-token checks on every
mutation. This shortens the existing critical section without removing Company
serialization. The synthetic CSV measurement does not close mapped-attribute,
supplier-XLSX or actual HTTP/queue acceptance.

The shared catalogue continuation is documented in
`docs/releases/2026_10_03_CATALOG_LOCK_PROTOCOL.md`. Its migration 004 depends on
the earlier counter migration and replaces Company counter writes with a
separate `catalog_product_capacities` row. Install and verify all three product
triggers plus the Company insert trigger with every writer stopped. Deploy
matching schema/code without mixed old/new workers; neither code version works
against the other's counter schema. Down copies current known/NULL counts back
before restoring matching old code. Review interrupted DDL explicitly.

Selected create/update and import paths hold Company shared for current
authorization, with capacity exclusive for admission. Include ordinary and
API-bound independent metadata edits during a chunk, same-product exclusion,
current credential/role/company revocation and a waiting create denied after the
last slot commits. Initialize NULL rows, verify zero drift and inspect live waits
at capacity. NULL scan fallback, legacy exclusive Company paths, categories and
mapped attribute definition/value locks can still contend. Preserve the separate
clean and instrumented samples. This local continuation does not close supplier
XLSX, coordinated staging rollout or actual HTTP/queue/supervisor acceptance.

## Mapped catalogue current-state follow-up (unit 27)

See `docs/releases/2026_10_03_MAPPED_CATALOG_CURRENT_STATE.md`: current option/pivot/response reads fix reproduced older-snapshot failures, with 139 focused tests / 638 assertions, Pint and PHPStan passing. Four complete synthetic CSV profiles import 1,000 category/select/multiselect products each into 10k/100k catalogues; all 4,000 source rows, tenant relations, audit bijections and capacity counts are checked. Shared Company permits independent metadata edits, while category/definition assignment locks still serialize. This does not close supplier XLSX, real supervisor/proxy/receiver, normal capacity-schema rollout or staging acceptance. Mapped update taxonomy retention is the next separate current-state investigation.
