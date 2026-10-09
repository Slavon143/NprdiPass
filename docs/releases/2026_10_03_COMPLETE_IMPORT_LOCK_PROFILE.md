# Complete import and admission policy follow-up — 2026-10-03

This follows the transactional/API authorization boundaries and capacity lock
footprint report. No usable external staging address was discovered: current
APP_URL is `http://nordipass.test`, the acceptance environment is loopback, and
the environment documentation contains `staging.example.com`. The same-day
GitHub discovery returned no environments, repository variables or deployments.
The following measurements are local evidence, not site acceptance.

## Complete pipeline before the admission-policy fix

PHP 8.4.26 / MySQL 8.0.46; separate owned schema and storage; no concurrent test
suite. Each clean case uploads, previews, submits and completely applies 1000
synthetic CSV products in four 250-row chunks. Its initial tenant has 10,000 or
100,000 eligible products, half draft/half active, plus 500 archived and 500
soft-deleted products. Every seed product has a default variant. Finite limits
allow exactly 1000 additional products; unlimited cases use null as before.

The submission calls the real actions with the authenticated actor/token bound
to the API mutation guard. Each chunk invokes the actual `ApplyImportJob::handle`
with scalar identities, including Job Center progress and success. Dispatch is
faked and handles are invoked manually; this does not test queue lag, reservations,
TCP, proxy or supervisor behavior. HTTP is faked with stray-request prevention.
The fixture has no mapped attributes, documents, readiness or outbound endpoints.

| Initial eligible products | Plan | Apply seconds | Maximum chunk seconds | Apply SQL | Capacity SQL seconds | Handler_read_next |
|---:|---|---:|---:|---:|---:|---:|
| 10,000 | finite | 24.310 | 6.395 | 24,105 | 9.612 | 10,499,999 |
| 10,000 | unlimited | 14.428 | 4.274 | 23,105 | 0 | 1,497 |
| 100,000 | finite | 91.161 | 23.112 | 24,105 | 77.124 | 100,500,997 |
| 100,000 | unlimited | 12.684 | 3.401 | 23,105 | 0 | 1,497 |

Preview takes 0.178–0.224 seconds. Each case verifies exactly 1000 applied records,
products, variants and creation audit rows, exact processed/created counters,
zero skipped rows, correct final capacity and successful Job Center state.
Source CSV hashes match across cases. PHP memory_limit is 128 MiB; the process
allocator lifetime peak reaches 66 MiB. Peaks accumulate across cases; native RSS
and a 100k-row supplier import are not measured here.

These are single local samples, not medians or production SLOs. At 100k, the
capacity probe alone consumes 77.124 of 91.161 apply seconds. SQL fingerprints
and session index-read counters confirm the remaining O(rows × catalogue size)
cost, rather than merely inferring it from an OFFSET query.

A separate instrumented 250-row import on that finite tenant starts real child
processes running `UpdateProductAction` on existing draft products. Same-company
edit takes 23.339 seconds; foreign-company edit finishes in 0.050 seconds while
the first waits. `performance_schema.data_lock_waits` identifies companies.PRIMARY
exclusive record locks as the blocker. The chunk takes 23.873 seconds including
process launch and observation. This sample is excluded from the clean table.
Moving only the admission scan to a counter cannot remove the shared authorizer's
Company mutex or the Company foreign-key checks used by product/audit inserts.

## Confirmed policy invariant and narrow fix

`CatalogProductCapacity::assertSlotAvailable` used current catalogue reads but
`EntitlementService::value` selected overrides/subscriptions with ordinary reads.
With an older outer REPEATABLE READ snapshot, a Company-first change committed
by another connection could reduce the limit to zero and still permit a complete
product/default-variant aggregate. Independent probes reproduce subscription
reduction, new zero override and revoked permissive override. Probe transactions
are rolled back; no rejected-policy product remains.

Admission now calls `EntitlementService::limitForMutation`. It requires a
transaction, takes Company first and reads the active override or subscription
under shared locks. Current reads bypass the older snapshot; the selected policy
cannot change before the admitted transaction finishes. Ordinary entitlement
reads keep their existing semantics. Override precedence, active/expiry filters,
subscription grant rules, fake-provider fallback and null/unlimited interpretation
are retained. No tariff, counter, migration or authorization cache was introduced.

All seven new regression cases fail on the saved baseline for the intended
policy assertions (five failures, two validation errors, 36 assertions, exit 2).
The final expanded selection passes **494 tests / 2047 assertions** in 418.861
seconds, exit 0 with JUnit errors/failures zero. It includes policy, capacity and
API concurrency plus Billing, imports, API and Fortnox features. This is a
selected regression run, not a new full-application suite. Pint passes the three
PHP files; PHPStan reports zero errors. The three run-start source hashes match
after verification.

The same four complete pipelines pass again on the final code, with separate
`post-fix` results and all 839 runtime source hashes verified after completion:

| Initial eligible products | Plan | Apply seconds | Maximum chunk seconds | Apply SQL | Capacity SQL seconds |
|---:|---|---:|---:|---:|---:|
| 10,000 | finite | 23.615 | 6.078 | 25,105 | 9.202 |
| 10,000 | unlimited | 13.307 | 3.476 | 24,105 | 0 |
| 100,000 | finite | 91.884 | 23.147 | 25,105 | 77.103 |
| 100,000 | unlimited | 13.619 | 3.600 | 24,105 | 0 |

Each case again verifies all 1000 rows/aggregates/audits and successful Job Center
state. All three independent old-snapshot policy probes now reject creation at
the committed zero limit. The repeated contention drill observes the same
companies.PRIMARY blocker: same-company edit 23.504 seconds, foreign-company edit
0.028 seconds, instrumented chunk 23.868 seconds. Live lock summaries also record
the new shared subscription/override index locks; the foreign sample demonstrates
that specific product edit, not freedom from blocking for all other tenant writes.
Lifetime PHP allocator peak remains 66 MiB under the 128 MiB limit.

The mutation policy method acquires its own Company mutex, even when admission
already owns it. This adds one Company query per imported product and keeps the
method's lock order self-contained. The guard fixes correctness; these single
samples do not establish a speed improvement. The expensive finite scan remains.

## Dependency-ordered remaining work

| Priority | Work | Dependency and acceptance |
|---|---|---|
| High | Actual staging pipeline | Obtain a real endpoint/owned tenant and anonymized supplier files; measure proxy/queue/supervisor, MariaDB if used, native memory and representative mapped workloads. This local run does not close that gate. |
| High | Constant-time finite admission | Preserve the now-current policy and cardinality invariants. Design a transactional counter with Company-first protocol, migration/backfill ownership, reconciliation and complete supported-writer inventory before implementation. |
| High | Company serialization cost | Measure after scan removal. Reducing this mutex requires a separate authorization/revocation and foreign-key protocol; a counter alone does not justify removing it. |
| Medium | Repeated writes/authorization reads | The final unlimited case still executes about 24 SQL statements per imported product; profile mapped workloads before batching or transaction-local reuse. Retain actor, tenant and audit boundaries. |

Counter writer inventory: `CreateProductAction::execute` and
`CreateProductWithDefaultVariantAction::execute` acquire a slot;
`RestoreProductAction::execute` reacquires an archived slot;
`ArchiveProductAction::execute` and `BulkArchiveProductsAction::execute` release
slots. `ActivateProductAction` and `ReturnProductToDraftAction` move between two
eligible states and have zero net delta. `BulkUpdateProductStatusesAction` delegates
to these actions. Imports, Fortnox and onboarding delegate creation to
`CreateProductAction`; `ProductAggregateCreator` is their low-level persistence
helper, not an independent quota boundary.

`Product` supports SoftDeletes, while the inspected runtime action/controller
paths expose archive/restore rather than product deletion or tenant transfer.
Any supported future soft delete/undelete, physical delete, tenant transfer,
maintenance/direct writer or fixture/seed path must declare counter ownership.
Raw SQL fixtures in this measurement deliberately bypass runtime actions and are
not evidence that a counter already covers direct writers. Test root/savepoint
rollback, retries, duplicate jobs, failed rows, last-slot concurrency and current
policy changes; do not implement an unchecked cached COUNT or reservation.

The proposed counter rollout should be reviewed as separate dependent units:

1. Add counter storage with an explicit unknown/uninitialized state. Keep the
   current scan authoritative. Add transactional delta maintenance to both create
   paths, restore and single/bulk archive before enabling counter reads. Maintain
   known counters on unlimited plans too, because a later plan can be finite.
   An unknown counter is never interpreted as zero. Choose one creation boundary
   so the aggregate helper and its callers cannot count the same product twice.
2. Backfill/reconcile under Company first in an owned, fresh root transaction.
   Establish the cardinality read only after obtaining that mutex; forbid reuse
   of an earlier caller snapshot. A COUNT is safe here only if the complete writer
   protocol is enforced. An initialized counter must equal the committed number
   of nondeleted draft/active products. A concurrent writer either precedes the
   backfill read or applies its delta after initialization.
3. Enable current counter reads under the retained Company protocol, with the
   existing locking scan for unknown state. Test parity, override/subscription
   changes, final-slot contention, archive/restore idempotency and every rollback
   boundary before activation. Counter writes, aggregate writes, record counters
   and audits must share the same transaction/savepoint outcome.
4. Measure complete finite imports again, then consider further mutex changes
   separately. Preserve reconciliation and report drift with tenant-scoped audit
   evidence. Do not approve a read switch from a successful migration alone.

This is a proposed implementation sequence, not an implemented or approved
counter schema. Rough effort is 3–5 engineering days for writer coverage,
backfill/reconciliation and activation regressions, excluding staging access and
deployment review. Company-lock redesign requires a separate estimate after
those measurements.

Evidence resides in the continuation's `complete-import-lock-profile` directory:
pipeline/query results, policy probes, source hashes, real contender results,
live lock summaries, negative controls and final checks. Initial profiling matched
all 839 application/config/migration/lockfile run-start hashes before the fix.
The first fixture run omitted the imports entitlement and was rejected; it is
retained separately and excluded from successful measurements. Its Laravel
exception renderer returned exit zero, so the harness now explicitly returns
nonzero on exceptions and requires a complete-results artifact.

No normal database or production configuration was changed. No application
migration was added, real provider called, or commit, push, PR or deployment
performed. Test migrations are confined to the owned disposable `_testing` schema.
After all processes finished, `nordipass_import_lock_profile_20261003_testing`
and the verified owned `storage/framework/audit-import-lock-profile-20261003`
directory were removed. Evidence and snapshots remain. The verified review unit is
`23-admission-policy-current-reads.patch`, comprising the two production files,
seven regression cases and this release/acceptance documentation. It is checked
and independently materialized against the saved continuation baseline.
