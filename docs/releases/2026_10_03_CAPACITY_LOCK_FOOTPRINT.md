# Capacity locking footprint, review unit 13

CatalogProductCapacity::assertSlotAvailable uses a tenant covering index to probe
the limiting row, after acquiring an exclusive Company row mutex. The previous
FOR UPDATE probe also locked every visited product PRIMARY record. At 100,000
eligible products, the live lock summary showed 100,000 PRIMARY record locks,
100,181 capacity-index record locks and two index gap locks. This is confirmed
behavior on local MySQL 8.0.46, not an inference from the query's covering index.

The capacity probe now uses sharedLock (LOCK IN SHARE MODE), retaining the
exclusive Company mutex. Its locking read still observes current committed
cardinality under REPEATABLE READ. All three admission paths retain that mutex:
CreateProductAction, CreateProductWithDefaultVariantAction and RestoreProductAction.
The covering-index shared read uses no product PRIMARY locks in the measurement.
Status/deletion changes still conflict with the capacity-index locks. Company
serialization, limits, filters and error messages retain their contracts.

## Regression evidence

- The new two-connection regression reproduces error 1205 on a direct brand-only
  update while the baseline holds capacity locks. The shared-read candidate passes:
  that update completes, while competing status changes and Company locking reads
  still time out. It tests the physical lock boundary, including its retained mutex.
- Existing tests serialize competing creates at the final slot and require a
  current read after an earlier snapshot has missed another committed create.
- Two new cases require admission after an archive or soft delete commits behind
  an older snapshot. The earlier COUNT still sees the old row; admission must
  observe its release and permit exactly one replacement aggregate.
- A new rollback case reuses the same create action after a rolled-back aggregate:
  product/variant rows disappear, the slot is reusable, and the next excess create
  is rejected.

A controlled unsafe COUNT substitution, loaded only in a separate test bootstrap,
fails all three snapshot cases: one missed rejection and two incorrect rejections.
The harness initially expected exit 1, while Pest returned exit 2 for errors as
well as a failure. Its XML was inspected; all three outcomes match the intended
negative control. No unsafe class was applied to the repository.

The candidate passes 11 focused tests / 67 assertions. The final production
verification also includes the added status-lock assertion and broader import,
product, lifecycle and MySQL coverage; final results are recorded below.

Final PHP 8.4.26 / MySQL verification passes: 136 tests / 1,009 assertions, zero
failures/errors/skips, exit zero. It covers both create paths, restore/lifecycle,
product operations, imports, MySQL constraints, capacity concurrency, actual
database retry and the real import worker's three abrupt-exit recovery boundaries.
Pint passes; PHPStan reports zero errors. The three verified PHP source hashes
remain unchanged. This is focused verification rather than a full-suite rerun.

## Capacity-only measurements

PHP 8.4.26, MySQL 8.0.46. Both implementations use fresh synthetic tenants with
10k or 100k eligible products, half draft / half active, plus 500 archived,
500 soft-deleted and 1,000 foreign-tenant products. Every seeded product has its
own tenant-owned default variant and the count is checked. These direct fixtures
exercise admission and schema relationships, not supplier data or readiness.

| Eligible products | Single free-slot check, baseline median ms | Shared median ms | 250 baseline checks, s | 250 shared checks, s |
|---|---:|---:|---:|---:|
| 10,000 | 18.216 | 10.347 | 4.716 | 2.451 |
| 100,000 | 187.000 | 78.583 | 44.322 | 19.506 |

Single-check medians use five separate transactions. Each 250-check sample holds
one transaction open and repeatedly checks the same catalogue without inserting
products: it isolates admission overhead rather than measuring an import.
The latter elapsed reductions are 48.01% and 55.99% in this local comparison.
These measurements do not establish end-to-end import or production latency.
PHP peak remains 38 MiB / 40 MiB respectively.

The scan count does not improve: 250 checks visit 2.5 million / 25 million index
entries (session Handler_read_next). Free/full/unlimited decisions match expected
cardinality in both measurements. Unlimited admission visits zero next-index
entries. PRIMARY product locks fall from 10k/100k to zero; the Company PRIMARY
mutex remains one exclusive record lock. Capacity-index shared locks remain.
Plan artifacts show the explicit baseline FOR UPDATE query's index path; candidate
lock mode is evidenced by its loaded class and live shared-lock summaries.

## Limits and dependency-ordered remaining work

The Company mutex remains a serialization point. For example, inserting
activity_log.company_id requires a Company foreign-key check; a complete audited
API mutation can still wait there even when its product PRIMARY row is free.
This unit reduces admission work and its product lock footprint. It does not
establish that full audited edits proceed during an import transaction.
MariaDB performance and real staging proxy/supervisor acceptance are unmeasured.

Remaining High performance debt: finite admission is O(catalogue size) per create
and O(chunk size × catalogue size) for a create chunk. A cached COUNT or unchecked
in-memory reservation must not replace its current-read invariant.

1. Measure Company wait time and finite admission in a complete import on staging.
   Audit lock order and authorization/current-read behavior under outer transactions
   before separating the mutex from Company; foreign keys and suspension/member
   changes are part of that boundary.
2. Enumerate and unify slot-changing operations: both create actions, restore,
   archive, soft delete/restore, bulk lifecycle changes and supported direct writers.
   Choose a consistent lock order and define reconciliation for any materialized
   counter. This is a separate design/review unit, not a cache added to this service.
3. If a transactional counter is justified, backfill under the write protocol,
   update it in the same transaction as each product mutation, test savepoint/root
   rollback and cross-tenant changes, and retain a reconciliation path.
4. Only then consider chunk admission/reservations, including failed rows, retries,
   cancellation, plan changes, nested transactions and worker crashes.

No migration, counter, commit, push, PR, deployment or production/external
integration call was performed. The patch base contains earlier uncommitted
remediation. The full suite from earlier units is separate evidence.

After all workers/tests/measurements ended, the owned
`nordipass_capacity_scale_20261003_testing` schema and
`storage/framework/audit-capacity-scale-20261003` storage were removed. Logs,
snapshots, lock summaries and measurements remain. The separate unit patch is
applied only to a temporary saved baseline and its four materialized files are
compared with the final workspace by SHA256.

Evidence: `C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/staging-acceptance-20261003/capacity-scale-audit/`.
