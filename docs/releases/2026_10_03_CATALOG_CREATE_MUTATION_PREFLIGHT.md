# Nested catalogue creation preflight

This continuation follows the transactional capacity counter. It removes one
duplicated authorization pass from `CreateProductAction` when the caller already
owns a transaction. The action still authorizes every creation inside its own
transaction/savepoint immediately before normalization, capacity admission and
aggregate writes. It stores no authorization result for reuse between rows.

## Execution path and scope

`ApplyImportJobAction` holds Company through a chunk and invokes the product
action in a row savepoint. Previously, the product action performed a complete
current authorization pass before opening its savepoint and another inside it.
`ProductAction::authorize` reads Company and delegates to `CompanyAuthorizer`;
inside a transaction this locks Company, User and membership, with an additional
current credential check for a bound API token. Both passes were inside the
import caller's existing transaction, prolonging the same critical section.

The revised action retains the original authorization and normalized-input
preflight when no transaction exists. When nested, it opens its savepoint and
performs the mandatory authorization there, then normalizes input once. Capacity,
tenant context, current permission/token checks, lock order, default variant,
category synchronization, creation audit and duplicate-slug handling remain on
the existing path. Invalid nested input is now rejected inside the action's
savepoint after authorization; standalone invalid input is still rejected before
opening a transaction. Authorization retains precedence over validation.

The change does not remove Company serialization, cache permissions, alter
capacity triggers, reduce import chunk sizes or modify worker retry/continuation
rules. Update and SKU/GTIN aggregate actions keep their existing preflights.
Directly replacing Company X with S would still contend with counter-trigger/FK
writes and introduce lock upgrades; splitting authorization and capacity requires
a separate coordinated protocol and administrative-boundary review.

## Verification evidence

Evidence is retained in the continuation's `catalog-mutation-preflight` directory.
Tests use PHP 8.4.26 and the owned MySQL 8.0.46 schema
`nordipass_mutation_preflight_20261003_testing`, with isolated storage and fake
external HTTP/dispatch. The application database is outside this verification.

The new query-budget regression was run before the production edit. Both cases
failed for the intended count: two nested creations performed four shared User
checks rather than two; with an API token they performed eight rather than four.
The initial test fixture lacked its boolean dataset; this setup error was fixed
before that negative control. The baseline failure and JUnit evidence are retained.

All nine new cases then pass with 77 assertions. They cover ordinary/API query
budgets, role/membership/user/company/token revocation at the new savepoint
boundary, repeated use of the same action after an own-transaction role change,
and standalone authorization/validation before any transaction begins. These
instrumented savepoint cases complement real competing-connection checks; their
query budget is performance coverage rather than a substitute for authorization
or concurrency tests.

- The existing transactional-authorization and import DB-retry/worker-crash
  files pass **16 cases / 269 assertions**, 336.697 seconds. They cover committed
  revocations behind older snapshots, locks held through outer commit, bounded
  retry, ambiguous commit acknowledgements and real abrupt worker exits.
- The initialized-counter last-slot concurrency case passes **1 / 9**, retaining
  capacity serialization alongside foreign-tenant and metadata writes.
- Billing, Imports, API, Fortnox, all Catalogue and Onboarding feature tests pass
  **920 / 4338**, 140.473 seconds. This is a selected feature run, not the entire
  application suite. JUnit reports zero errors, failures or skips.
- The final union is **946 unique cases / 4693 assertions**. Each input run is
  identified in `verification-summary.json`; negative-control failures are
  separate evidence, not remaining final failures.
- Pint checks both PHP files successfully; PHPStan reports zero errors. The
  previous profile's 842 runtime files differ only in `CreateProductAction`.
  Its saved baseline hash matches that previous profile's source manifest.

## Complete local pipeline

The unchanged previous harness runs four clean 1000-row synthetic CSV imports,
each upload/preview/submission plus four real 250-row worker handles. Tenants
start with 10k/100k eligible products, plus 500 archived and 500 deleted, and
backfilled counters. Every case verifies all applied records, products, default
variants, creation audits, job totals, Job Center success and zero counter drift.
Worker dispatch is fake; handles are invoked manually with scalar identifiers.

| Initial eligible | Plan | Previous apply s | Revised apply s | Maximum chunk s | Laravel apply queries | Capacity scans | Handler_read_next |
|---:|---|---:|---:|---:|---:|---:|---:|
| 10,000 | finite | 14.131 | 12.760 | 3.639 | 20,105 | 0 | 534 |
| 10,000 | unlimited | 12.486 | 11.138 | 2.875 | 20,105 | 0 | 1,498 |
| 100,000 | finite | 12.596 | 11.016 | 2.851 | 20,105 | 0 | 1,499 |
| 100,000 | unlimited | 12.561 | 11.058 | 2.824 | 20,105 | 0 | 1,501 |

Every case removes exactly **4000 Laravel queries**, from 24,105 to 20,105
(16.6%). A worker's unbound actor no longer repeats the ordinary Company read
and Company/User/membership lock reads for each of the 1000 rows. Current
authorization still executes for every row. Laravel counts exclude SQL inside
counter triggers; outer statement time includes trigger execution. Source CSV
SHA256 remains
`5d4d5ff027df6ed26fd5cc30d69dea5e0893ffb2342a85538d749761e90c6577`.

The separate instrumented 250-row contention sample takes 3.179 seconds,
including child startup/observation. Same-company `UpdateProductAction` takes
2.920 seconds (previous 3.279); the foreign-company edit takes 0.028 seconds.
Live waits still identify `companies.PRIMARY` X record locks. Both edits persist,
and a final independent reconciliation confirms exactly 101,250 eligible
products with no drift. All 842 runtime source hashes still match run start.

These are single local samples, not medians or production SLOs. Timing depends
on machine/cache state; the deterministic query reduction is the primary result.
The fixture has no mapped attributes, documents, readiness or external receivers.
Parent PHP memory_limit is 128 MiB and lifetime allocator peak reaches 66 MiB;
native RSS and actual HTTP/proxy/queue/supervisor behavior are not measured.

## Operational follow-up

This unit adds no migration. Activation still depends on the previous counter's
coordinated DDL/backfill rollout. Actual HTTP, queue supervisor, scheduler,
receiver idempotency and representative supplier XLSX acceptance remain open.
Preserve final per-row authorization and current policy locks when optimizing
other catalogue mutations. Company serialization must not be removed solely
because the local import becomes faster.

After all owned test/profile/child processes completed, the owned testing schema
and verified `storage/framework/audit-mutation-preflight-20261003` tree were
removed. The normal database/configuration and unrelated local PHP server were
not changed. No commit, push, PR, deployment or production migration was run.
The four-file `25-catalog-create-mutation-preflight.patch` is independently
materialized against the saved continuation baseline and compared with the final
source; earlier accepted changes are not included in that patch.
