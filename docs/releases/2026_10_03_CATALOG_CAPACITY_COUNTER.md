# Transactional catalogue capacity counter — 2026-10-03

This continues the complete-import admission profile. Review unit 24 adds a
nullable `companies.catalog_products_count`, transactional product triggers,
fresh-root reconciliation and a current counter read at finite admission.
Authorization and the current subscription/override policy locks remain in place.
Ordinary display counts retain their existing query/snapshot semantics.

## Writer protocol and invariants

The count is exactly nondeleted draft/active products. NULL is unknown, never
zero. Existing and newly created companies initially remain unknown until
`catalog:capacity-reconcile --repair` initializes them. Unknown admission uses
the existing shared covering-index probe; unlimited admission still avoids it.
The internal column is not fillable and is hidden from model serialization.

Three AFTER INSERT/UPDATE/DELETE product triggers maintain known counts in the
same database transaction and savepoint as the product mutation. Both creation
actions, their default-variant aggregate helper, imports, Fortnox and onboarding
therefore acquire exactly one count increment. Single/bulk archive release slots;
restore reacquires them; draft/active transitions and metadata updates have zero
delta. Soft delete/undelete, physical DELETE, SQL/seed/factory writes and eligible
tenant moves also apply the cardinality delta. A tenant move here describes
database maintenance only; it does not authorize moving a runtime aggregate or
bypass its composite foreign keys. Counts update on unlimited plans too.

Import root rollback, row savepoint rollback and retries roll back/reapply the
trigger delta with the product. A committed checkpoint or repeated completion
does not create a second delta. No application-side reservation or delta cache
was added. Underflow fails the SQL mutation rather than silently clamping drift.

Each cardinality-changing trigger UPDATE locks Company even if its count is
NULL. Filtering to initialized counters would allow a SQL writer to escape the
mutex during backfill and lose its delta. Metadata-only updates do not take this
additional lock. Runtime actions retain Company-first order. Direct maintenance
transactions must acquire affected Company rows in ascending ID order before
product writes; triggers maintain counts but cannot make arbitrary reverse-order
SQL transactions deadlock-free. The tenant-move trigger orders its two Company
updates by ID. Existing bounded import transaction retry behavior is unchanged.

## Backfill and reconciliation

`CatalogCapacityReconciler::reconcile` receives a scalar Company ID, rejects any
existing caller transaction, owns a fresh root transaction with bounded deadlock
retries, locks Company first, then establishes its first consistent COUNT read.
A pending SQL product change sees the initialized count after its trigger can
acquire that mutex and applies its delta. Reconciliation deliberately avoids a
locking product scan, which would invert locks against that pending SQL writer.
Count repair and tenant-scoped audit evidence commit or roll back together.

`catalog:capacity-reconcile` requires exactly one of `--company=<uuid>` and
`--all-companies`. Default mode reports drift/unknown state without modifying
the count, writes tenant-scoped drift audit evidence and returns 1 on drift.
`--repair` explicitly initializes/repairs it and returns 0 after success. Invalid
selectors return 2 and a missing UUID returns 3. Matching counters do not emit
redundant audit rows. All-company mode chunks IDs, but owns one transaction per
Company; it does not hold a transaction across tenants.

## Deployment and operational acceptance

1. Pause/drain all catalogue-changing workers and HTTP/maintenance writers before
   applying or reversing the trigger DDL. MySQL DDL is not an atomic installation
   of the column plus all three triggers. Verify all three trigger definitions
   and privileges after migration before resuming writes. A failed partial DDL
   needs operator repair before retry, not an assumed successful activation.
2. Unknown counts allow the normal application to continue using its old scan.
   Once trigger installation is complete, backfill selected tenants or all
   tenants with `--repair`, then require a check-only run to return 0. This can run
   with normal Company-first writers; a large tenant COUNT holds its mutex for
   the duration. Run reconciliation for new tenants as well.
3. Retain periodic drift checks and their output/audit evidence. Privileged
   manual counter writes, `TRUNCATE products`, disabling/dropping triggers or
   bulk restore/DDL that bypasses them are outside the row-trigger protocol.
   Such maintenance requires paused writers, explicit invalidation to NULL and
   reconciliation before counter admission resumes. Company deletion cascades
   remove the owning counter; no live tenant count remains to maintain.
4. Code rollback to scanning admission can retain the counter column/triggers.
   Drain writers before removing them. Never retain counter-reading workers
   against a schema with missing triggers. No production migration/backfill was
   executed in this work.

MySQL 8 local evidence does not establish MariaDB compatibility, trigger-definer
privileges on the deployment host, actual staging queue/supervisor behavior or
supplier XLSX fidelity. The staging access/corpus gate remains open. Company
serialization remains a separate follow-up after the finite scan is removed;
this counter does not justify caching authorization or removing that mutex.

## Verification

PHP 8.4.26 / MySQL 8.0.46, on the owned disposable
`nordipass_capacity_counter_20261003_testing` schema and isolated storage:

- Expanded Billing, Imports, API, Fortnox and all Catalog features pass
  **909 tests / 4259 assertions**, 135.908 seconds, exit 0; JUnit errors/failures
  are zero. This is a selected feature run, not the whole application suite.
- The union of latest scoped concurrency/capacity/recovery results and that
  expanded run is **949 unique cases / 4788 assertions**, with no remaining
  errors, failures or skips. `verification-summary.json` identifies each source
  JUnit run and de-duplicates repeated cases; this is not one 949-test command.
- Initial new fixtures exposed three setup errors: deleting a referenced default
  variant aggregate, and two missing-current-company backfill setups. The FK and
  authorization failures remain recorded. Corrected fixtures pass targeted
  reruns; the FK refusal is now asserted before valid physical deletion.
- Fourteen policy boundary cases cover both NULL/initialized state, current
  subscription reductions/increases and override creation/revocation behind
  older snapshots, and selected policy locks held through transaction completion.
  Counter cases also cover both creation actions, lifecycle idempotency/full
  restore refusal, model/SQL deletes, tenant moves, finite downgrades, root and
  savepoint rollback, current cardinality and retained last-slot serialization.
- Two real child-writer backfill cases pass **42 assertions**. Live lock waits
  confirm that the SQL writer has changed its product and is waiting on Company
  inside its trigger while the count is still NULL. Backfill sees the committed
  product, initializes one, then the release applies once and leaves zero. An
  independent reconciliation matches the catalogue afterward.
- All eight real worker-crash/database-retry cases pass with additional counter
  assertions: abrupt exit before commit, committed checkpoint/continuation,
  retryable schema/lock errors, aborted chunks, ambiguous acknowledgement and
  repeated completion preserve exactly the committed product count.
- Saved baseline admission fails the no-product-scan regression for the intended
  boolean assertion (one case, six assertions, exit 1). An independent missing
  update-trigger control produces stored=1/actual=0 and is detected by check-only
  reconciliation; its schema is rebuilt before subsequent verification.
- Migration down/up/backfill roundtrip passes: all three triggers/column are
  removed, products remain, reinstall yields NULL rather than zero, backfill
  restores the count and the reinstalled update trigger releases it correctly.
- Pint checks all twelve changed/new PHP files successfully. PHPStan reports
  zero errors. All **842** run-start application/config/migration/lockfile hashes
  match after the final pipeline; final contention reconciliation also passes.

## Complete local pipeline and remaining mutex cost

The same previous profile runs four clean 1000-row synthetic CSV pipelines,
each upload/preview/submit followed by four actual 250-row worker handles. Initial
tenants contain 10k/100k eligible products plus 500 archived and 500 deleted,
with default variants. Counters are backfilled before submission. Every run
verifies all 1000 applied records, products, variants, creation audits, job
counters, Job Center success, final cardinality/counter and zero reconciliation
drift. The instrumented contention sample is separate from clean timing.

| Initial eligible | Plan | Previous apply s | Counter apply s | Maximum chunk s | Laravel apply queries | Capacity scans | Handler_read_next |
|---:|---|---:|---:|---:|---:|---:|---:|
| 10,000 | finite | 23.615 | 14.131 | 4.019 | 24,105 | 0 | 574 |
| 10,000 | unlimited | 13.307 | 12.486 | 3.167 | 24,105 | 0 | 1,497 |
| 100,000 | finite | 91.884 | 12.596 | 3.272 | 24,105 | 0 | 1,499 |
| 100,000 | unlimited | 13.619 | 12.561 | 3.306 | 24,105 | 0 | 1,501 |

Previous 100k finite admission consumed 77.103 SQL seconds and about 100.5 million
index-next reads; no admission product scan remains in the initialized cases.
Laravel query counts exclude trigger-internal SQL; measured outer INSERT/UPDATE
time includes trigger execution. The counter is not free maintenance. Backfill
is a separate one-off COUNT: 0.015–0.026 seconds at 10k and 0.108–0.111 at 100k in
these local samples, holding Company while it runs. Source CSV SHA256 remains
`5d4d5ff027df6ed26fd5cc30d69dea5e0893ffb2342a85538d749761e90c6577`.

The separate 250-row contention chunk takes 3.532 seconds including child-process
launch/observation. Same-company `UpdateProductAction` takes 3.279 seconds;
foreign-company edit takes 0.025 seconds. Live waits identify companies.PRIMARY
exclusive record locks as before. The instrumentation now starts at the first
Company lock, before product writes; its early lock summary is not a like-for-like
comparison with the old post-scan product lock footprint. Final count after this
chunk is exactly 101,250, with no drift. Removing the scan has shortened the
observed wait, but Company serialization remains and needs a separate protocol.

These are single local samples, not medians or production SLOs. Dispatch is fake
and worker handles are invoked manually; the fixture has no mapped attributes,
documents, readiness or external endpoints. PHP memory_limit=128 MiB; allocator
lifetime peak reaches 66 MiB across cases. Native RSS, actual queue lag/proxy/
supervisor and representative supplier XLSX are still outside this measurement.

After all processes finished, the owned testing schema and verified
`storage/framework/audit-capacity-counter-20261003` directory were removed.
The normal database/configuration, external providers and unrelated local server
were not changed. No commit, push, PR or deployment was performed. Evidence and
the independently materialized `24-transactional-catalog-capacity-counter.patch`
remain in the continuation's `catalog-capacity-counter` directory. The coordinated
review unit contains storage/writer protocol, backfill/read activation, regression
coverage and operational documentation; enabling reads without those dependencies
is not an accepted rollout.
