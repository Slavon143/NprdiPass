# Boolean attribute idempotency, review unit 11

SyncProductAttributeValuesAction and SyncVariantAttributeValuesAction compared
MySQL's raw 0 to `(string) false`, an empty string. Repeating an existing false
value was therefore classified as a change. Eloquent itself skipped a physical
UPDATE because its casts considered the value unchanged, but the action still
issued an unnecessary options DELETE and wrote a false attribute-change audit.

The old repeat-sync no-op test used only text. New tests with real MySQL fail for
Product/false and Variant/false: audit count grows from one to two. Both true cases
pass on the old code. This is a confirmed pre-existing defect, not a consequence
of the import merge change.

Each comparator now converts an expected boolean to 0/1 before its existing raw
comparison. Other column types and null checks remain unchanged. No historical
audit rows are deleted or rewritten. The intentional behavior change is that a
true no-op no longer produces an attribute-change audit or options DELETE.

The regression covers both Product and Variant with false and true, advances the
clock, requires unchanged raw storage/timestamps, zero unnecessary UPDATE/DELETE,
one creation-change audit, and successful explicit clearing afterward. Focused
coverage with unit 10: 147 tests / 842 assertions. Pint and PHPStan pass.

The final PHP 8.4.26 full suite passes: 2,535 tests / 11,048 assertions, zero
failures/errors/skips and exit zero. The six changed PHP files retain their
verified SHA256 values. The owned disposable schema and fixture storage have
been removed after verification; regression evidence remains.
The mapped fixture in unit 10 uses select/multiselect, so its timing measurements
do not measure boolean no-op savings.

Evidence and review patch live alongside unit 10 in
`C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/staging-acceptance-20261003/mapped-write-optimization/`.
No production database, historical readiness result or external integration was
modified. The fixes are separate review units; no commits or PRs were published.
