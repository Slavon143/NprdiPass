# Mapped catalogue current reads and resource boundaries, review unit 27

Mapped import review found confirmed data-integrity failures before attempting
another concurrency optimization. MySQL locking reads of Product/Variant and
AttributeDefinition were mixed with ordinary snapshot reads of selectable options,
multiselect assignments and returned state. Holding Company or Definition after
a change commits does not renew an older REPEATABLE READ transaction snapshot.
The defects also affect standalone actions called within an existing transaction.

| Finding | Severity | Evidence | Remediation |
|---|---|---|---|
| Archived options accepted | High | Four failing two-connection cases | Current validity read, fixed here |
| Omitted multiselect reverted | High | Failing retained-selection case | Current pivot reads, fixed here |
| Stale no-op response | Medium | Two failing response cases | Current parent and relation reads, fixed here |

`AttributeAction::authorize` also performs an ordinary Company fetch before the
current locking authorization read inside a mutation transaction. By inspection,
waiting there behind another committed mutation can leave an older snapshot even
without a caller-supplied outer transaction. The tests force the older snapshot
explicitly; they do not time that particular authorization wait interleaving.

## Confirmed failures and implementation

- `AttributeValueValidator::activeOptions` used an ordinary aggregate count.
  A second connection archives an option after the first establishes its snapshot.
  Both Product and Variant sync accepted it, for select and multiselect. Four
  regressions fail on the saved pre-unit source with the expected exception absent.
- `SyncProductAttributeValuesAction::mergedPayload` read selectedOptions through
  ordinary eager loading. A second connection commits a different selection.
  A merge omitting that attribute reconstructs the older selection and attempts
  to restore it. The fifth pre-unit regression returns the first option instead
  of the committed second option. The action's persist comparison also reads the
  same stale relation; locking the value row alone does not make the pivot read current.
- Both sync actions used `refresh()->load(...)` for their response. Two additional
  regressions fail after the initial options/pivot fix: a no-op returns the old
  Product/Variant name. Current parent, stored value, definition and selected option
  must be returned without producing another attribute-change audit.

Three runtime files change: `app/Support/Catalog/AttributeValueValidator.php`,
`app/Actions/Catalog/Attributes/SyncProductAttributeValuesAction.php` and
`app/Actions/Catalog/Attributes/SyncVariantAttributeValuesAction.php`. Option validity
uses an ordered shared locking read of actual requested rows, rather than an
aggregate. Relations needed for validation, merge/comparison and the response use
current shared reads with explicit tenant filters. The returned parent is reloaded
with a current locking read. Preview's explicitly supplied option snapshot remains
separate from mutation validation.

Product/Variant exclusion, the exclusive definition locks, scalar Variant's
Company mutex, current company/user/membership/credential guards, row/chunk
savepoints, checkpoints, audit atomicity, explicit clears and archived stored values
remain. The intended behavior change rejects already-invalid options, preserves
committed omitted selections and returns current state for a no-op. Historical
audits or values are not rewritten. No schema change is introduced by this unit.

## Regression and quality evidence

Tests in `tests/Concurrency/MappedAttributeCurrentStateTest.php` use two real MySQL
connections and commit fixture changes before taking the mutation locks. They
prove the old ordinary snapshot still sees the option as active. Coverage also
requires valid option state to remain protected until commit, then rejects approval
withdrawal; omitted merge retains the latest selection without a false audit; both
no-op responses include current parent/value/definition/option state.

`tests/Feature/Imports/ImportScalingTest.php` retains its three-row query budget:
three exclusive mutation reads and three shared return reads per relevant table,
with no preliminary snapshot reads. The previous assertion ignored unscoped return
reads; adding tenant-scoped current response reads intentionally makes them visible
to this query filter. The assertion is updated for those required reads, not removed.

139 final tests / 638 assertions pass with zero failures, errors or skips.
Coverage includes attribute actions/UI/integrity, imports, product/variant attribute
API and validator rules alongside all ten new real-connection cases. Pint passes
for all six PHP files; PHPStan reports zero errors with one worker. This is a
focused run, not a new full-suite result. The earlier broader pass found one
expected query-budget mismatch and all eight import retry/crash cases passed;
it is retained as intermediate evidence, not described as a passing gate.
Initial static analysis flagged Collection::count; final validation compares the
actual ordered requested option IDs after materializing their locking read.
No analyser suppression or baseline entry was added.

## Resource contention and complete mapped profile

`tests/Concurrency/MappedCatalogResourceLockTest.php` holds an actual action's
transaction while another connection edits a different Product. Metadata editing
commits, but an assignment to the same category waits on `categories`; attribute
sync waits on `attribute_definitions`. The test requires MySQL error 1205 at the
corresponding resource query, and rollback removes the pending assignments while
preserving the independently committed metadata. These exclusive resource locks
are retained. This unit is a correctness fix and measurement, not a claim that
mapped writes now proceed concurrently.

PHP 8.4.26 / MySQL 8.0.46 complete synthetic CSV pipeline: upload,
preview/mapping, API-token-bound submission and scalar worker handles over four
250-row chunks. Each case imports **1,000 products into an existing 10k or 100k
eligible-product catalogue**, with a category, select and multiselect. Another
500 archived and 500 deleted products are excluded from capacity. Two active
attribute definitions are used; this is not a 500-definition benchmark.

| Catalogue / plan | Apply seconds | Slowest chunk seconds | Apply SQL | PHP peak MiB |
|---|---:|---:|---:|---:|
| 10000-finite | 29.335 | 7.706 | 50105 | 70 |
| 10000-unlimited | 29.826 | 8.553 | 50105 | 80 |
| 100000-finite | 31.277 | 8.669 | 50105 | 84 |
| 100000-unlimited | 33.377 | 10.327 | 50105 | 84 |

All four cases require exact metadata, tenant-owned default variants and
category/attribute/option relations for every imported product, all applied
records, matching product/attribute audits, Job Center success, zero skipped
rows, zero capacity drift and zero initialized admission scans. Source SHA256: `83858cab9e6caaa9b78345e1561f0b2cb661347e0761a3d30df667ab492ccba4`. HTTP is blocked; queue dispatch
is faked and handles are invoked manually. Tests/static analysis do not run
concurrently with the profile. Single local samples establish no production
SLO, elapsed-time improvement or real worker/proxy acceptance. PHP peaks are
cumulative within one process across these four sequential cases, under an
explicit 128M memory limit; they are not isolated per-case memory measurements.
50,105 apply SQL statements per 1,000 mapped rows remain measured query-volume
debt. No paired pre-unit timing profile was run, so these results do not measure
the performance cost or benefit of this correctness patch.

## Remaining dependency order and limits

1. Review this unit on the previous remediation base. Before running catalogue
   mutations on the ordinary application database, complete the coordinated
   migration/code/backfill procedure in `2026_10_03_CATALOG_LOCK_PROTOCOL.md`.
   The capacity-row schema required by preceding code was only tested on owned
   disposable schemas; it has not been installed on the ordinary database.
2. Reproduce mapped **update** taxonomy retention under an older snapshot and
   a competing category edit. `ApplyImportJobAction::update` currently constructs
   retained primary/additional categories through ordinary reads before
   `UpdateProductAction` locks the Product. This is a code-derived risk, not a
   reproduced finding or a fix included here. The profile below creates products.
3. Only after current-state invariants are covered, review every category/definition/
   option writer before changing exclusive resource locks. Test independent
   assignments, same-product exclusion, taxonomy/option mutation and revocation
   order, deadlock recovery and quota admission together. A Company shared lock
   alone is insufficient justification for another lock change.
4. Actual anonymized supplier XLSX, HTTP/proxy/supervisor/scheduler and external
   receiver acceptance, coordinated staging DDL/rollback, MariaDB execution and
   historical DPP executable-release retention remain open.

No commit, push, PR, deployment, normal database migration, external delivery or
production passport publication was performed. Evidence is preserved under
`C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/staging-acceptance-20261003/mapped-current-state`. Review patches are independently checked/applied to a saved
snapshot rather than the unrelated dirty HEAD. All 844 previously recorded runtime
files are checked; only the three listed runtime files change in this unit.
