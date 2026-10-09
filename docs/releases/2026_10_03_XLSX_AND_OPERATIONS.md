# XLSX and operational acceptance follow-up

This follows `2026_10_03_SCALING_AND_PROVENANCE.md`. Earlier dirty work was
preserved separately. No commit, push, production migration, deployment or
external webhook delivery was performed. The XLSX reader connection was
explicitly approved after a concrete patch and compatibility evidence were
provided. All database measurements use disposable testing schemas.

## Changes and concrete evidence

| Boundary | Before | Change and regression |
|---|---|---|
| Select/multiselect preview | 500 mapped rows issued 1,004 queries | Fresh tenant-scoped option snapshots per preview batch reduce this fixture to 4 queries. Snapshots validate company, definition, active status and approval. The next batch sees an archived option; mutation callers retain fresh SQL validation. |
| Finite catalogue capacity | Locking offset scan fetched product records | Covering `(company_id, deleted_at, status, id)` index with explicit index selection preserves company serialization and current reads. Two-connection repeatable-read regression rejects a slot filled after an older consistent snapshot. This remains a linear locking scan. |
| Resolver dashboard p95 | Only request duration samples existed | Optional bounded cache histogram reports sample count, window, collection status and nearest-rank histogram upper bound. Disabled/empty/unsupported/unavailable remains unknown; telemetry can collect independently of request logging. |
| Webhook worker interruption | Recovery boundaries had no actual worker exit drill | A real database queue worker exits after simulated receiver success or after local delivery persistence before queue acknowledgement. Leases prevent premature recovery; retries reuse identity and receiver deduplication applies once. Completed delivery is not resent. |
| XLSX shared strings and worksheet | Native chunk loading repeatedly parsed shared strings/worksheet XML, with high native memory | XMLReader streams worksheet data; shared strings and their offset index live on disk. A small workbook retains PhpSpreadsheet's styles, calendar and cell formatting. First-sheet, sparse physical row, formula rejection, date/number/boolean/rich-text/error formatting and spool cleanup have regression coverage. |
| Parent relation lookups during mapped apply | Product attribute/category/selection relations omitted company in the parent lookup; company-first indexes were unusable. EXPLAIN showed full scans of about 20k values / 10k pivots when only 1–2 rows were needed | Add parent-first indexes for product/variant values, selections and category assignments. A 1,200-parent regression fails with a full scan before the migration and bounds all five relation plans afterward. Existing company indexes and composite tenant FKs remain unchanged. |
| Temporary database failures during import | A live index migration caused MySQL 1412; the row catch converted 500 technical failures into failed rows and continued | Structured PDO classification propagates schema-change, deadlock, lock-timeout and connection-loss errors out of the row and chunk. Only an owned root transaction retries rollback-safe concurrency/schema failures, at most three attempts. Caller-owned transactions and connection loss are never automatically replayed. Permanent row integrity failures retain their existing behavior. |

`ImportFileReader` retains archive expansion checks, header normalization and
empty-row behavior. CSV handling is unchanged. The parser rejects DTDs,
external data-part relationships, invalid shared-string references, malformed
XML, unknown cell types/styles, invalid row coordinates and a first chartsheet.
Compatibility is established for the tested corpus, not every OOXML producer.
Metadata and individual rows still consume memory; the measured peak is not a
universal memory bound for every file under the archive size limit.

## Deployment dependencies

Apply `2026_10_03_000001_add_product_capacity_index.php` before releasing code
that forces `products_capacity_index`. Adding the index on a large production
table requires its own database deployment procedure. Restore the old code
before removing this index on rollback. No production migration was run here.

`2026_10_03_000002_add_catalog_relationship_indexes.php` is additive and
reversible. It supports Eloquent parent lookups without replacing tenant filters
or foreign keys. Assess index creation time and write/storage overhead in the
database deployment procedure; the local run does not certify online DDL behavior
for every supported MySQL/MariaDB version.
Quiesce affected import workers before these schema changes, and test the migration
and restart procedure on the supported staging database version. The observed
1412 failure is concrete evidence that applying DDL during an active import is unsafe.

When import retries exhaust, the queue failure handler marks the job failed and
keeps committed checkpoints and unapplied valid records. An authorized restart
loads current state and continues from that checkpoint. After connection loss,
reconcile the stored job/row checkpoint before restarting: the database commit
may have succeeded even if its acknowledgement was lost. This change does not
raise queue attempt counts or blindly replay a lost connection.

Resolver collection defaults to off. Enable it only after checking shared cache
and lock tables/backend, host prefixes, sample volume and overhead. The histogram
uses the current minute and preceding 59 minutes; contended locks skip samples.
It reports an upper bound rather than exact p95 and does not replace an APM.
File cache aggregates one host. Unsupported queue backends need native metrics.

The webhook drill uses fake HTTP in the child worker and a local receiver marker.
It proves interruption/recovery behavior, not an external endpoint's idempotency
contract. Production delivery remains at least once.

## Measurements

Runtime: PHP 8.5.2, MySQL 8.0.46. PHP 8.4 is the project target but was not the
available ordinary local CLI. Some measurements overlap the full suite; these
are local acceptance measurements, not production SLOs.

The same 100,000-product XLSX is 4,226,205 bytes, with seven mapped columns:
name, slug, category, select color, multiselect tags, brand and manufacturer.
The fixture uses a separate tenant with unlimited product entitlement.

| Measurement | Result |
|---|---:|
| Previous native reader, complete preview | 323.696 s; 1,811 queries; 172 MiB PHP peak; 1,981.1 MiB process peak |
| Streaming reader alone, every source value checked | 38.545 s; 30 MiB PHP peak; about 52 MiB sampled process peak; `memory_limit=128M` |
| Final reader, including optional row-coordinate compatibility fix | 43.808 s; 30 MiB PHP peak; every value in 100,001 raw rows checked; `memory_limit=128M` |
| Integrated streaming preview, complete domain planning | 96.663 s; 1,811 queries; 54 MiB PHP peak; 100,000 valid / 0 invalid |
| Clean final preview, with all indexes already installed | 78.996 s; 1,811 queries; 58 MiB PHP peak; 100,000 valid / 0 invalid |
| Clean final mapped apply, all 100k products | 2,519.363 s (about 42 min); 4,703,212 queries; 400 chunks; slowest chunk 10.232 s; 58 MiB PHP peak |
| Clean final process memory | 78.25 MiB peak working set, sampled once per second |

The raw reader benchmark is not a persistence benchmark. Its full-row checksum is
`f4b7b8b2b4deae9fc35f1081edc889da65b3b2d4c9b38061824f09b04944602c`.
The integrated preview measurement includes real MySQL planning/persistence.
The clean final apply completed with 100,000 products, 100,000 default variants,
200,000 attribute values, 100,000 multiselect selections and 100,000 primary
category assignments. Processed and created counters are both 100,000; skipped
is zero. The runner exited zero (`xlsx-clean.log`, `xlsx-clean-exitcode.txt`).
This exercises real domain actions and MySQL persistence, with fake queue/HTTP;
it is not a real import-worker or HTTP timeout acceptance run. About 47 SQL
statements per product remain, so successful acceptance does not establish
adequate production throughput or efficient finite-capacity imports.
An independent persistence verifier checked every product's name, slug, brand,
manufacturer, category, select option and multiselect option against its source
row, checked the default variant link and tenant ownership of variant/value/
selection relationships, and rejected duplicate or missing source identifiers.
All 100,000 products passed; the final job is completed with exact counters and
no import errors (`xlsx-persistence-final.json`, `xlsx-final-checkpoint.json`,
`xlsx-final-errors.json`).

The first mapped apply exposed missing parent-first indexes: time per 1,000
rows increased as relations scanned growing tables. I mistakenly ran the index
migration during that import in the disposable scale schema, at 13,000–13,750
committed rows. It caused 500 MySQL 1412 errors, which the original row catch
misclassified as data failures. That run stopped at 41,500 processed / 41,000
created / 500 skipped; it is not a successful acceptance result. Its metadata,
failed-row evidence and counters are preserved without resetting them. A clean
run uses a new tenant/job, the same unchanged XLSX, and all indexes present before
the first row; no DDL is performed during that run.
Before/after EXPLAIN and elapsed relation reads are retained in
`relationships-before.json` / `relationships-after.json`, with exact transition
progress in `relationship-index-transition.log`.

For a mixed 100k catalogue containing 80k draft/active products, the standalone
finite-capacity check averaged 176.335 ms before and 143.946 ms after the covering
index. A full limit correctly denied a slot and one additional slot was available.
This modest improvement does not establish constant-time capacity checks.

## Verification evidence

- Imports and root database retry regressions after the final fix:
  48 tests, 303 assertions, no errors or failures (`imports-transient-final.log/xml`).
- The new technical-failure regressions reproduced six failures before the fix
  (7 initial cases, one permanent-integrity-error control passing). The final
  coverage also includes connection loss with no automatic replay and a lost
  acknowledgement after an actual commit, with no duplicated writes/audits.
- Final XLSX formatter compatibility/security/cleanup coverage: 9 cases,
  18 assertions, including optional row coordinates under both workbook calendars.
- Selectable-option and import regressions: 38 tests, 182 assertions.
- Broader focused imports/attributes/capacity/observability: 112 tests, 535 assertions.
- Capacity concurrency/collector checks: 15 tests, 102 assertions.
- Real webhook worker exit drill: 2 tests, 32 assertions.
- All 22 changed PHP files pass Pint; PHPStan reports zero errors after the
  final temporary-database-failure fix (`pint-transient-final.log`,
  `phpstan-transient-final.log`).
- Final full suite: 2,523 tests, 10,863 assertions, no failures, exit code zero
  (`full-transient-final.log/xml`); this run includes the final retry fix.
- Parent relation regression: full scan reproduced before migration; afterward
  all five access paths pass, 1 test and 26 assertions.

Evidence, exact reader patch, baseline, XML/log results and benchmark fixtures:
`C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/`.
After verification, both owned disposable schemas
(`nordipass_audit_fixes_20261002_testing` and
`nordipass_scale_20261003_testing`) and their isolated fixture storage were
removed. The original XLSX, old failed-run evidence, metadata, baselines and
test/benchmark logs remain in the evidence directory. No production schema
or ordinary application storage was removed.

## Review units

1. Option snapshots in preview, validator input checks, relation PHPDoc and query
   budget/stale/foreign-option regression.
2. Capacity index and index selection, with repeatable-read/last-slot tests.
   The migration is a prerequisite for the changed query.
3. Optional resolver collector, middleware/snapshot/config/environment wiring,
   operational documentation and collector/backend failure regressions.
4. Real webhook worker interruption fixtures and recovery regression; this unit
   changes verification only, not delivery semantics.
5. Streaming XLSX reader and import connection, with native formatter comparison,
   sparse/formula/security/cleanup regressions and full pipeline acceptance.
6. Additive parent relationship indexes and query-plan regression, discovered
   during the mapped apply measurement. This unit changes access paths only.
7. Temporary database failure classification and bounded root transaction retry,
   with chunk rollback, prior checkpoint, permanent integrity-error, real MySQL
   lock-timeout and lost-acknowledgement-after-commit regressions.

Review each unit against the saved continuation baseline. The aggregate Git diff
also contains older remediation and unrelated pre-existing work. No commits or
pull requests were created automatically.

## Remaining work in dependency order

| Priority | Work | Dependency and estimate |
|---|---|---|
| High | Exercise the site's actual supplier XLSX corpus and import queue worker/HTTP timeouts in staging; preview remains a single transaction | Current compatibility/failure regressions first; 1–3 days plus access to representative files |
| Medium | Finite capacity remains linear; writes retain per-product authorization/attribute queries; source SHA is checked per chunk | Profile finite plans and mapped writes before reservation/counter changes; 2–4 days, covering create/archive/restore/rollback/concurrency |
| Medium | Retain executable DPP release artifacts and decide legacy draft migration | Provenance is recorded, but historical code and missing legacy evidence cannot be invented; retention/replay policy first, then 2–5 days |
| Medium | Enable/measure production telemetry and verify external webhook receiver idempotency, scheduler and worker supervision | Backend configuration and operational ownership; 1–2 days each; no exactly-once claim |
| Low | Extract remaining long actions and settle regulatory/cache/revocation/tariff policy | Boundary regressions first; small review units, 1–3 days for extraction; product decisions need owners |

These remaining items are acceptance/debt, not evidence of another confirmed
Critical incident. Passing tests do not prove the absence of all defects.

The retention gap has concrete configuration evidence: `.github/workflows/release.yml`
keeps its uploaded artifact for 7 days, and `deploy/scripts/deploy.sh` defaults
to keeping 5 extracted releases. The build includes vendor and composer.lock
through its rsync copy, but those short-lived copies do not establish historical
replay for the lifetime of published evidence. A durable archive destination and
retention duration need an explicit release/product policy; this follow-up does
not invent one or rewrite historical passport results.
