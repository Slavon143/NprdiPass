# Product return-state reads, review unit 12

CreateProductAction returned `refresh()->load(...)` after creating the aggregate,
assigning categories and writing its audit. ProductAggregateCreator had already
loaded defaultVariant. Laravel's Model::refresh reloads the root attributes and
already-loaded relations; the following load read defaultVariant a second time.
The saved query profile confirms three variant reads per created product.

The return now uses `refresh()->loadMissing(...)`. Both existing product refreshes
remain. The final refresh still obtains current root fields and defaultVariant;
loadMissing obtains primaryCategory and categories without repeating that read.
This is a single-line production change on the saved remediation baseline.
Authorization, capacity mutex, category validation, transaction, audit and import
checkpoint behavior are unchanged. Other action return paths were not changed:
their loaded-relation state must be assessed independently.

## Regression coverage

Two cases create with and without categories. A test-only audit-created callback
updates persisted product/variant/category fields after the aggregate's initial
load. Returned root fields and relations must reflect those updates and all three
return relations must be loaded. Cached return values or removal of refresh fail
these assertions. The same test requires two variant reads, rather than three.
The callback dispatcher is cloned and restored in finally; production listeners
are preserved. Both cases fail on the saved baseline specifically on the three
reads, with their preceding freshness assertions passing.

PHP 8.4.26 / MySQL focused verification passes: 137 tests, 912 assertions, zero
failures/errors/skips, exit zero. Coverage includes product/variant actions and UI,
imports, and MySQL integrity tests. Pint passes; PHPStan reports zero errors.

An additional 29 tests / 234 assertions pass for product capacity concurrency,
catalog audit coverage and the real database import worker's three abrupt-exit
boundaries (before commit, after commit, after continuation enqueue). Capacity
serialization and checkpoint recovery without duplicate products/audits still
hold. Combined verification: 166 tests / 1,146 assertions, zero failures/errors/
skips. These are focused runs rather than a new full-suite result.

## Controlled mapped XLSX measurement

Three pairs use fresh tenants and the same synthetic 500-row XLSX with category,
select and multiselect mappings. Pair two reverses the order. Baseline action is
loaded in a separate PHP process; repository files are not swapped. External HTTP
is blocked and queue delivery is faked for these synchronous measurements.

| Pair | Baseline seconds | After seconds |
|---|---:|---:|
| 1 | 15.585 | 13.874 |
| 2, reversed order | 11.716 | 11.566 |
| 3 | 12.100 | 11.906 |
| Median | 12.100 | 11.906 |

Every baseline uses 22,528 apply SQL statements; every changed run uses 22,028,
500 fewer (2.22%). Variant eager reads fall from 1,500 to 1,000. PHP peak remains
50 MiB. Local median elapsed time is 1.60% lower; elapsed time varies, and this
small local sample does not establish production or 100k-product performance.

All six runs complete two chunks with 500 created, zero skipped, 500 products and
variants, 1,000 attribute values, 500 selected-option links and primary categories.
All 3,000 products have exact source values, tenant-owned relations and one
creation audit each. Source SHA256:
`fb2d86ea4edad5fdd63423257a7b916ede62a9795cf65a1ea20d3537d8d53bbc`.

## Review scope and remaining work

The patch contains CreateProductAction, its regression in ProductActionsTest,
and this release evidence. Its base already includes earlier uncommitted fixes;
it must be reviewed on that base rather than treated as a clean-HEAD patch.
No commit, push, PR, deployment, production database or real external delivery
was performed. The full suite from unit 11 is earlier evidence, not a full-suite
result for this change.

SHA256 checks confirm both verified PHP files stayed unchanged throughout
verification. The owned `nordipass_product_refresh_20261003_testing` schema and
`storage/framework/audit-product-refresh-20261003` fixture storage were removed
after test and measurement processes ended. Baseline snapshots, logs, XML results,
XLSX source and six measurements remain in the evidence directory. The separate
unit patch is checked by applying it only to a temporary saved baseline and
comparing all three materialized files with the final workspace by SHA256.

Repeated membership/company reads, finite-capacity scans, remaining product
reload paths and per-chunk source hashing remain separate measured debt. Actual
supplier XLSX and acceptance behind staging proxy/supervisor are still unavailable
in the inspected locations. Historical executable DPP ruleset retention remains
an explicit domain/operations decision.

Evidence: `C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/staging-acceptance-20261003/product-refresh-optimization/`.
