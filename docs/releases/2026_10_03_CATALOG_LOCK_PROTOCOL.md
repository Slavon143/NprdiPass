# Catalogue capacity and shared authorization protocol

This continuation removes the Company write mutex from selected catalogue
create/update paths. A product admission or import chunk now holds a separate
capacity row. An unrelated product's metadata update can finish while admission
remains uncommitted; a competing create still waits and must recheck the limit.

## Dependencies and execution paths

Deploy the capacity migration/code (26a) before enabling the shared authorization
callers (26b). Migration 004 depends on migration 003's nullable Company counter
and three product triggers. It copies known counts and unknown NULL values into
`catalog_product_capacities`, replaces the product triggers, creates a Company
insert trigger for new capacity rows and then removes the old Company column.

`CreateProductAction` and `UpdateProductAction` use
`ProductAction::authorize → CompanyAuthorizer::authorizeCatalogWrite`. The
SKU/GTIN aggregate creation path opts in directly. `ApplyImportJobAction`
opts in at its chunk/row authorization boundaries, and mapped product values
use `SyncProductAttributeValuesAction`'s explicit attribute authorization flag.
There is no permission cache, chunk size change or worker continuation change.

| Boundary | Company | Other retained locks |
|---|---|---|
| Selected catalogue authorization | Shared current read | User, membership and bound PAT shared current reads |
| Product creation admission | Shared | Capacity row exclusive; selected entitlement shared |
| Independent product metadata update | Shared | Target Product exclusive |
| Product attribute synchronization | Shared | Target Product, definitions and existing values exclusive |
| Capacity reconciliation | Shared, including deleted companies | Capacity exclusive before the first ordinary COUNT |
| Generic authorization, membership/admin/billing and other catalogue mutations | Existing exclusive mode | Existing resource and policy protocol |

The opt-in method accepts only CatalogCreate/CatalogUpdate. Existing
`CompanyAuthorizer::authorize`, API mutation and entitlement guards retain their
exclusive default. Administrative permission changes, suspension and token
revocation continue to wait for the current authorization locks. Current reads
still reject committed revocation, expiry and missing abilities behind an older
repeatable-read snapshot. Other actions with the same permission name are not
automatically converted to shared mode.

## Counter invariants

`CatalogProductCapacity::lockCapacity` requires a transaction and locks Company
shared before capacity exclusive. Known counters use no product admission scan.
NULL retains the existing covering-index shared probe until explicit backfill.
The relation and its count are hidden from Company serialization. The legacy counter attribute also stays hidden for paused rollback states; they are not
request-fillable tenant fields.

The three product triggers apply eligible draft/active, nondeleted deltas inside
the product statement's transaction. Metadata and draft/active zero-delta updates
do not lock capacity. Tenant moves acquire affected capacity rows in ascending
company order. A missing capacity row raises SQLSTATE 45000 for cardinality
changes rather than silently discarding a delta. Application admission also
fails closed. Explicit root reconciliation can reconstruct a missing row.

`CatalogCapacityReconciler` requires its own fresh root transaction. It takes
Company S and capacity X before establishing the ordinary COUNT snapshot.
Audit and repair commit atomically. The competing SQL archive/delete test
observes a live wait at capacity; it applies exactly one delta after backfill
commits. Restore acquires capacity before locking the product.

This is an application quota boundary, not a database-wide quota constraint:
privileged direct SQL can insert beyond a commercial limit, while triggers
maintain its exact counter. TRUNCATE, trigger removal or restored data that
bypasses triggers requires paused writers and explicit reconciliation.

## Verification

Tests use PHP 8.4.26 and MySQL 8.0.46 in the exact owned schema `nordipass_catalog_locks_20261003_testing`, with isolated storage and fake external HTTP/dispatch.

| Run | Cases | Assertions |
|---|---:|---:|
| Capacity transfer, SQL deltas and backfill phase | 15 | 202 |
| Shared locks, current authorization/policy, create, retry/crash and default API guards | 53 | 596 |
| Capacity concurrency plus Billing/Imports/API/Fortnox/Catalogue/Onboarding/Privacy/CLI features | 948 | 4434 |
| Final rollback serialization and missing-row repair | 2 | 40 |
| Real Laravel kernel/Sanctum request during unbound worker admission | 1 | 15 |

The final union is **1017 unique cases / 5248 assertions**, with no errors, failures or skips. The rerun replaces two earlier migration cases in the union. This is a selected suite, not the full application suite. The capacity phase precedes shared callers; the 53-case and expanded runs execute shared mode. Final rollback and kernel regressions cover the later serialization amendment and request layers.

The added cases prove independent ordinary/API-bound edits, same-product exclusion, outer rollback with a committed child edit/audit, current company/user/membership/PAT locks, committed revoke/expiry/ability changes behind older snapshots, and rejection of administrative shared permissions. The kernel case authenticates a real bearer token, runs middleware/controller, updates last_used_at and commits the independent product edit while unbound worker admission stays open; it uses Laravel internal dispatch, not TCP.

A rollback review found that the new model exposed the restored legacy counter attribute through toArray after down. A negative roundtrip test reproduces this. Company now hides both the new relation and the legacy attribute; the final two migration cases pass. The first kernel fixture received 403 because it retained session Company context from a different DB alias. Clearing request context, as the worker does, fixes the fixture without weakening authorization; the first failure is retained separately.

The negative baseline is preserved separately: both ordinary and API-bound
independent metadata actions failed on the previous Company X protocol. The
standalone control observed MySQL 1205 at the current Company `FOR UPDATE`
query. Final results exclude those expected negative controls.

The initial parallel PHPStan attempt failed because Windows could not allocate
virtual memory. A separate configuration limits PHPStan to one worker; the final
run reports zero errors. No system memory settings were changed. Pint passes
the changed PHP files.

## Complete local pipeline

Four clean 1000-row synthetic CSV imports each run upload, preview, submission and four 250-row worker handles. Tenants begin with 10k/100k eligible products, plus 500 archived and 500 deleted, and backfilled counters. Every case verifies all applied records, products, default variants, audits, job totals/Job Center success and zero drift. A preliminary run overlapped cached PHPStan in its first timed case; it is preserved under profile and excluded from this table. These final samples run after recreating the owned schema, with static analysis/tests complete.

| Initial eligible | Plan | Previous apply s | Final apply s | Max chunk s | Laravel apply queries | Capacity scans |
|---:|---|---:|---:|---:|---:|---:|
| 10,000 | finite | 12.760 | 11.089 | 2.839 | 21,105 | 0 |
| 10,000 | unlimited | 11.138 | 10.983 | 2.799 | 21,105 | 0 |
| 100,000 | finite | 11.016 | 11.197 | 2.919 | 21,105 | 0 |
| 100,000 | unlimited | 11.058 | 11.825 | 3.050 | 21,105 | 0 |

The new capacity row adds one Laravel admission query per product (21,105 versus 20,105 for 1000 rows); it preserves zero initialized product scans. Trigger-internal lock reads are additional work outside the Laravel query total. Each case uses the same CSV SHA256 `5d4d5ff027df6ed26fd5cc30d69dea5e0893ffb2342a85538d749761e90c6577`. PHP memory_limit is 128 MiB; lifetime allocator peak is 66 MiB.

In the separate instrumented 250-row drill, the same-company metadata action finishes in **0.033 s** (previous 2.920 s); the foreign-company edit takes 0.032 s. Both commit before the parent releases capacity. A competing create waits 3.035 s, then is denied after the chunk fills the limit. Live performance_schema evidence identifies `catalog_product_capacities.PRIMARY` X record locks, and the chunk including observation takes 3.233 s. Final independent reconciliation confirms 101,250 eligible products with no drift. All 844 runtime source hashes still match run start; 830 files match the previous continuation and only the 14 expected runtime files changed.

These are single local synthetic samples, with manually invoked real worker
handles and fake dispatch/HTTP, not actual supervisor, Redis, proxy or production
acceptance. The fixture has no mapped attributes, documents or readiness work.
Allocator peak is PHP memory, not native process RSS. Timings depend on machine
and cache state; shared authorization primarily changes contention, not a promise
of lower total import latency. Laravel query totals exclude statements within
triggers. Source hashes are compared after profiling.

## Coordinated installation and rollback

1. Review pending migrations and confirm migration 003, exact trigger definitions,
   a recoverable backup and trigger/DDL privileges. Stop and drain every writer:
   HTTP mutations, queue workers, scheduler/CLI, integrations and privileged SQL.
   MySQL trigger/column DDL is not transactional.
2. Install migration 004 while writers remain stopped. Verify the capacity table,
   migrated known/NULL values, all three product triggers and the Company insert
   trigger. An interrupted DDL run needs explicit inspection/recovery; do not
   resume writers or blindly rerun a partially applied migration.
3. Activate matching capacity code and shared callers together before restarting
   workers. No old/new worker mix: old code references the removed Company
   column, new code requires the capacity table. Clear/rebuild deployment caches
   and restart long-lived processes through the site's normal release procedure.
4. Initialize NULL rows with `catalog:capacity-reconcile --all-companies --repair`
   and require a subsequent check without `--repair` to report zero drift.
   Verify current policy/revocation boundaries and last-slot serialization.
   New tenants start with NULL and need scheduled reconciliation or their
   retained scan fallback. Resume only after schema/code/counter checks.
5. For rollback, stop/drain writers again. Migration 004 down copies current known
   and NULL counts back to Company and restores the three legacy product
   triggers. Restore matching pre-26 code before resuming. Do not run old code
   against the new schema or new code against the legacy schema.

Only the exact disposable MySQL testing schema was migrated here. The ordinary
application database and production/staging rollout are outside this change.
The local source therefore requires the coordinated migration before catalogue
mutations can run against an existing application database.

## Remaining acceptance and contention

Company serialization remains on legacy catalogue paths, Fortnox flows,
publication, billing and administration. Edits to the same Product still wait.
Category and mapped attribute resource locks can also serialize independent
products; their behavior needs separate profiling before changing it. Mixed
shared/exclusive calls in an outer transaction can require upgrades, so adding a
shared caller requires reviewing its complete nested execution path.

Sanctum Guard also writes last_used_at during bearer authentication. A request using the same PAT can therefore wait on the PAT S lock held by another API-bound mutation; this code-derived limitation is not timed here. Scalar import workers do not hold an HTTP PAT, as exercised by the kernel regression. No credential lock was removed. MariaDB was not executed in this validation.

Representative anonymized supplier XLSX, actual HTTP/queue/supervisor/scheduler,
receiver idempotency and coordinated staging DDL/rollback acceptance remain open.
This continuation does not close historical DPP executable-release retention or
introduce a production scale/SLO claim.
