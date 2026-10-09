# NordiPass scaling and execution provenance follow-up

This continues the 2026-10-02 forensic remediation. Existing dirty work was
preserved in a separate baseline before changes. No commits, pushes,
production migrations, external deliveries or deployments were performed.

## Confirmed changes

| Boundary | Evidence before | Result / implementation | Regression |
|---|---|---|---|
| Import preview | 1,011 rows required 2,035 SQL queries | `ProductImportRowPlanner::planBatch` resolves tenant-scoped products/categories in bounded batches; `PreviewImportJobAction` inserts plan/error batches inside the original job transaction | Query budget, cross-batch duplicate row, error-to-record identity, failed later batch preserves previous record IDs/fingerprint |
| Import apply | Whole-job SUM aggregate on every chunk | `ApplyImportJobAction` commits chunk deltas with the locked job; failed chunks discard deltas, terminal duplicates contribute nothing | Mixed successful/failed rows, injected persistence failure and retry, duplicate completion |
| Import row rollback | An exception after saving an applied record rolled back its product but retained `target_product_id` in memory; saving failure then raised MySQL FK error 1452 and aborted the chunk | Refresh the record after savepoint rollback before persisting failure | Fault after applied-record write leaves one successful product, one failed row with no rolled-back reference, and exact committed counters |
| Bad import identifiers | A 300-character slug threw before row persistence, aborting all preview rows | Planner converts slug/category normalization failures into row errors | Three invalid rows and one valid row survive the same preview |
| DPP implementation identity | Configuration fingerprint could stay identical after handler replacement | `ReadinessExecutionProvenance` records application/lock/PHP identity and actual resolved handlers; draft and evaluation identities are stored separately | Container binding changes execution fingerprint, frozen configuration remains independent, old evidence unchanged, anonymous handlers leak no file paths |
| Legacy readiness page | Repeated live catalogue queries per product | `DppReadinessProfileBatch` loads assignments/templates/catalogue/requirements once per page; definitions are reused only inside that batch | One vs 25 products bounded SQL and score/blocker equality with standalone evaluation, both frozen and legacy drafts |
| Category profile selection | Catalogue projection omitted `active_template_id`; resolver selected v2 for a category assigned v1 | `ProductCatalogQuery` retains the template FK | Real catalogue query resolves the assigned version/fingerprint |
| Publication assets | Source, staged and final bytes were loaded into PHP strings; failed partial writes were not all owned by cleanup | `PublicationAssetStager` uses disk-backed spooling, stream hashing/verification and registered attempted keys | 40 MiB round trip below 8 MiB allocator growth, exact checksum, partial stage/promotion failures, real outer rollback/commit suites |
| Misleading publication test | Hash mock could throw before assets existed; an assertion after `expectException` was unreachable | Storage failure test now injects on immutable asset creation, proves its promoted file exists, and verifies cleanup; validation hashing test asserts rollback after catching its exception | Failure point must be reached before the test can pass |
| Resolver telemetry | No separate resolver timing sample | `PublicPassportResolver` records actual elapsed time in scoped `RequestTelemetry`; request logs include `public_resolver_ms` | Actual success/miss execution, positive bounded duration, reset between requests |
| Readiness API documentation | Success response omitted the actual API envelope; legacy empty passport UUID / nullable draft UUID were not represented | OpenAPI documents `data/meta/error`, execution provenance and existing legacy sentinels | YAML parsing and targeted schema-reference/envelope/sentinel checks; API suite |
| Duplicate services | No runtime references to two alternate publication encoder/snapshot services | Removed only `Services/Passports/Publication/CanonicalJsonEncoder` and `PassportSnapshotBuilder`; active services remain under `Services/Passports` | Runtime call graph search, publication suites, static analysis |

The XLSX HTTP export already used `readfile` in this continuation's baseline.
The previous report's final-ZIP response-string debt is therefore stale;
no new export change is attributed to this follow-up.

## DPP provenance contract

The existing configuration `rule_set_fingerprint` contract is preserved.
`profile_details.execution` identifies current executed code;
`draft_execution` identifies creation-time code when available.
`execution_comparison` is `matched`, `changed`, or `legacy_unknown`.
The manifest explicitly declares `replay_guarantee: provenance_only`.

This makes build changes observable and preserves their evidence. It does not
archive old PHP, execute source stored in the database, invent legacy
requirements, or retroactively re-evaluate published evidence. Retained release
artifacts are still necessary for historical executable replay. Worker restart
is required when deploying code; source identity is cached only within a
request/job scope. An unrelated application change can mark the release
comparison changed; it does not automatically invalidate the draft.

## Benchmark method and limits

Real MySQL transactions, real create/preview/apply actions, four CSV columns,
250 rows per apply chunk, unique products with a default variant, no category
or attribute mappings. A disposable tenant has unlimited entitlement for the
successful pipeline measurement. Counts of products, variants, processed and
created rows must equal the fixture size; skipped rows must be zero.

The first benchmark exercised the default 100-product tariff and correctly
rejected the remaining rows. It is not counted as successful throughput. The
verified fixture changes only isolated benchmark entitlements. Tariff counting
semantics remain draft/active products.

The 100k benchmark has a separate database and storage from the test suite.
Some of its execution overlaps the full suite; results are local acceptance
measurements under mixed load, not production SLOs. Native OS working-set
samples are retained separately from PHP allocator peaks.

| Fixture | Preview | Preview SQL queries | Apply | Apply SQL queries | Chunks | PHP allocator peak |
|---|---:|---:|---:|---:|---:|---:|
| 10,000 CSV products | 1.507 s | 151 | 107.783 s | 200,329 | 40 | 42 MiB |
| 100,000 CSV products | 15.475 s | 1,411 | 1,274.472 s | 2,003,209 | 400 | 50 MiB |

Both successful runs verified all products and default variants, exact
processed/created counters and zero skipped rows. The 100k process reached
approximately 68.21 MiB native peak working set. The slowest apply chunk was
5.984 s. Apply still performs about 20 SQL statements per product; bounded
memory and correct counts do not establish adequate production throughput.

Ten finite-capacity checks on the completed 100k catalogue averaged 212.175 ms
(maximum 369.186 ms). A limit equal to the current count correctly denied
creation. This confirms that the remaining locking offset scan is costly.

Runtime: PHP 8.5.2, MySQL 8.0.46. The project's target PHP 8.4 was not available
as the ordinary local CLI and is not claimed as verified by these runs.

## Verification

- Full suite: 2,493 tests, 10,599 assertions, no failures (`full-final.log/xml`).
- The row rollback issue was found after that full run. Following its fix,
  imports and API suites passed: 341 tests, 1,154 assertions
  (`imports-final.log/xml`), including the regression that failed with FK 1452
  before the fix (`row-rollback-before.log`). The full run predates this final
  fix; focused verification covers it.
- PHPStan: zero errors after the final fix (`phpstan-final.json`).
- Pint: changed PHP files passed, including the final two-file check
  (`pint-complete.log`, `pint-row-verified.log`).
- `git diff --check` passed. OpenAPI YAML parses and the readiness envelope,
  provenance reference and legacy sentinel schemas were checked directly.
- Benchmarks use separate disposable MySQL schemas, fake HTTP/queue and isolated
  storage. No production database or external delivery was used.

## Remaining acceptance / debt

This table describes the state at this report's original verification point.
The subsequent XLSX/attribute, capacity, telemetry and worker-drill evidence is
tracked in [2026_10_03_XLSX_AND_OPERATIONS.md](2026_10_03_XLSX_AND_OPERATIONS.md).

| Priority | Remaining item | Dependency / acceptance |
|---|---|---|
| High | XLSX parsing still reparses XML/shared strings; preview remains one transaction. A complete 100k XLSX/attribute-heavy pipeline and queue-worker/HTTP timing are not proven by the CSV fixture | Preserve formatting/formula/sparse-sheet regressions; staging file corpus, RSS/timeout and failure-resume measurements before parser/checkpoint redesign |
| Medium | Finite capacity remains a locking offset scan; source SHA is reread per chunk; select/multiselect attribute validation can still query per row | Profile actual finite plans and mapped attributes before introducing counters or cached authorization/validation |
| Medium | Historical PHP replay requires archived release artifacts; legacy snapshots remain incomplete where they were never recorded | Explicit migration/release-retention policy; never backfill current catalogue as historical evidence |
| Medium | `public_resolver_ms` samples exist, but dashboard p95 and unsupported queue-backend metrics remain unknown | Connect production time-series/queue monitoring; retain unknown rather than fabricate zero |
| Medium | Receiver success followed by a worker crash can cause a repeated webhook; terminal delivery recovery needs operator ownership | Receiver idempotency, scheduler/worker checks and staged crash drill; terminal rows are not blindly reset by `queue:retry` |
| Low | Long actions retain some architectural coupling; legal completeness, asset cache/revocation policy and tariff semantics require product decisions | Small reviewed changes after boundary tests; no architecture rewrite or policy invention |

No new production incident or complete absence of defects is inferred from
passing tests. These acceptance items are not reported as completed code fixes.

## Review units

1. Import batching/counters/row normalization with import/API regressions.
2. DPP execution provenance and API documentation.
3. Legacy page batching and category-template projection, preserving live semantics.
4. Streaming publication assets and corrected fault injection; duplicate removal.
5. Resolver timing collection and operational documentation.

Some files already contained earlier work; split by logical hunks against the
saved baseline. No PR or commit was created automatically.

Remediation order and rough effort for one engineer, excluding staging access
and product decisions: establish the heavy XLSX/attribute workload and worker
measurements first (1–3 days); use those results for safe parser/checkpoint work
(3–7 days) and capacity/attribute query changes (2–4 days). Connect production
timing and queue collectors (1–2 days) and run webhook crash/recovery drills
(1–2 days) independently. Historical replay needs a release-retention decision
before implementation (2–5 days after that decision). Architectural extraction
comes last and should preserve the established boundary regressions. These are
estimates, not completed work or delivery commitments.

Evidence and baseline:
`C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/`.
