# Mapped attribute reads, review unit 10

Mapped import previously read active definitions and existing product values to
build a replacement payload, then SyncProductAttributeValuesAction read them again
under locks. The saved SQL profile and a failing query-budget regression confirm
these repeated reads. ApplyImportJobAction now passes its imported UUID/value map
to the action's merge operation. Merge builds retained values from the same locked
definitions and values used by validation and persistence.

The existing execute operation keeps its full-replacement contract. Merge retains
omitted active values, treats explicit blanks as clears, revalidates the entire
result including omitted selectable options, and leaves archived values intact.
Fresh membership/company/lifecycle checks, option validation, row/chunk locks,
capacity serialization, audit, API contracts and checkpoint behavior remain.
No authorization cache, cached capacity counter or bulk write bypass was added.

## Regression coverage

- Import query budget requires locked reads and rejects the duplicate preliminary
  definition/value reads. It failed on the saved baseline and passes after wiring.
- All seven attribute types, large decimal precision, false, leap-day date,
  select/multiselect pivots and archived storage survive a merge. Explicit clears
  and full replacement remain distinct operations.
- Foreign definitions/products, revoked membership, suspended company and an
  archived product behind a stale model remain rejected.
- A mapped product update with an omitted option archived after preview fails
  atomically: product changes and their audit roll back, previous values remain.

## Measurement and verification

Initial PHP 8.4.26 mapped XLSX comparison, 500 rows / two chunks:

| | Saved baseline | After merge |
|---|---:|---:|
| Apply SQL | 23,528 | 22,528 |
| Apply seconds | 13.626 | 15.461 |
| SQL milliseconds | 7,338.29 | 8,314.24 |
| PHP peak MiB | 50 | 50 |

The deterministic improvement is 1,000 fewer SQL statements (4.25%), not a
demonstrated elapsed-time improvement from this single pair. Exact values and
tenant-owned default variants, categories and attributes were checked for every
product; 500 products/variants, 1,000 attribute values, 500 selections/category
links and 500 creation audits were present, with zero skipped rows.

Final paired measurement used three fresh tenants per implementation after the
full suite, reversing the second pair's order. Baseline classes were loaded from
the saved snapshot in their own PHP process; repository files were not swapped.
Every fixture must be new, so a completed import cannot be measured as a replay.

| Pair | Baseline seconds | After seconds |
|---|---:|---:|
| 1 | 11.758 | 11.197 |
| 2, reversed order | 11.979 | 11.334 |
| 3 | 11.914 | 11.307 |
| Median | 11.914 | 11.307 |

All baseline runs use 23,528 SQL statements; all after runs use 22,528. The local
median is 5.10% lower, with 50 MiB PHP peak unchanged. All 3,000 products across
the six runs have exact source values and tenant-owned relations. This small
local fixture is not a production or 100k-product SLA; the initial slower single
pair is retained as evidence of elapsed-time variability.

Pint passes; PHPStan reports zero errors. Focused coverage for units 10/11:
147 tests / 842 assertions, exit zero. The intermediate full run was deliberately
stopped after discovery of the boolean no-op defect and is not a passing result.
The final PHP 8.4.26 full suite passes: 2,535 tests / 11,048 assertions, zero
failures/errors/skips, exit zero (`full-after-boolean.log/xml`). SHA256 checks
confirm the six verified PHP files remained unchanged. The owned
`nordipass_mapped_writes_20261003_testing` schema and isolated storage were removed
after all processes ended; logs, snapshots, source XLSX and measurements remain.

Evidence: `C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/staging-acceptance-20261003/mapped-write-optimization/`.
The saved baseline already includes earlier uncommitted remediation. This unit
must be reviewed on that common base; no commit, push, PR or deploy was performed.

Repeated membership/company reads (2,004 / 2,000 per 500 rows), product/default
variant reloads, finite capacity scans and per-chunk source hashing remain measured
debt. Reducing them requires separate invariant-preserving changes. Actual supplier
XLSX, proxy/supervisor acceptance and production performance remain unverified.
