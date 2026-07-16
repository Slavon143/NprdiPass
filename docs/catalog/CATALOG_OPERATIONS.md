# Catalog Operations

## Scope

This document describes the Catalog Operations infrastructure for NordiPass R1.12. Operations include integrity scanning, catalog statistics, media orphan detection, and safe media cleanup — all with tenant isolation and read-only default behavior.

## Architecture

```
Command Layer (Console)
    |
Operational Services (Read-only / Safe Cleanup)
    |
Integrity Checks (Read-only Scanners)
    |
Catalog Database (MySQL 8)
```

Operations use:
- Read-only query scanners for diagnostics
- Existing Catalog Actions for business mutations (none added in R1.12)
- Separate Maintenance services for technical cleanup

## Integrity Scanner

### Architecture

- `CatalogIntegrityScanner` — Orchestrates all integrity checks
- `CatalogIntegrityCheck` (contract) — Interface for individual checks
- `CatalogIntegrityIssue` — DTO representing a single issue
- `CatalogIntegrityReport` — Aggregate report with issue counts
- `CatalogIntegritySeverity` — Severity enum (info, warning, error, critical)

### Registered Checks

| Check | Code | Resource Types |
|-------|------|----------------|
| CategoryIntegrityCheck | `category_integrity` | Categories, pivot |
| ProductIntegrityCheck | `product_integrity` | Products, pointers |
| VariantIntegrityCheck | `variant_integrity` | Variants, pointers |
| IdentifierIntegrityCheck | `identifier_integrity` | SKU, GTIN, MPN |
| AttributeIntegrityCheck | `attribute_integrity` | Definitions, Options, Values |
| MediaIntegrityCheck | `media_integrity` | Media records, files |
| LifecycleIntegrityCheck | `lifecycle_integrity` | Status consistency |
| TenantOwnershipIntegrityCheck | `tenant_ownership_integrity` | Cross-company relations |

### Issue Severity

| Severity | Meaning |
|----------|---------|
| `info` | Operational observation |
| `warning` | Incomplete/draft data, minor issues |
| `error` | Application invariant violation |
| `critical` | Tenant leak risk, broken ownership, unsafe resource |

### Specific Checks

#### Category Integrity

- `catalog.category.parent_tenant_mismatch` (critical) — Parent from different company
- `catalog.category.self_parent` (critical) — Category is own parent
- `catalog.category.depth_exceeded` (error) — Depth exceeds MAX_DEPTH (5)
- `catalog.category.product_primary_category_tenant_mismatch` (critical) — Primary category from different company
- `catalog.category.pivot_tenant_mismatch` (critical) — Pivot company_id mismatch
- `catalog.category.product_primary_missing_from_pivot` (error) — Primary category not in pivot

#### Product Integrity

- `catalog.product.default_variant_missing` (error) — Default variant doesn't exist
- `catalog.product.default_variant_wrong_product` (critical) — Default variant from different product
- `catalog.product.default_variant_tenant_mismatch` (critical) — Default variant from different company
- `catalog.product.primary_category_tenant_mismatch` (critical) — Primary category from different company
- `catalog.product.primary_media_wrong_product` (error) — Primary media from different product
- `catalog.product.primary_media_tenant_mismatch` (critical) — Primary media from different company
- `catalog.product.active_not_ready` (warning) — Active product has readiness blockers
- `catalog.product.no_variants` (error) — Product has zero variants

#### Variant Integrity

- `catalog.variant.product_tenant_mismatch` (critical) — Variant company differs from product
- `catalog.variant.default_archived` (error) — Default variant is archived
- `catalog.variant.default_wrong_product` (critical) — Default variant points to wrong product
- `catalog.variant.primary_media_wrong_variant` (error) — Primary media from different variant
- `catalog.variant.primary_media_tenant_mismatch` (critical) — Primary media from different company
- `catalog.variant.active_product_no_available_variant` (error) — Active product has no available variants

#### Identifier Integrity

- `catalog.identifier.sku_duplicate` (error) — Duplicate SKU in company
- `catalog.identifier.gtin_duplicate` (error) — Duplicate GTIN in company
- `catalog.identifier.sku_not_normalized` (warning) — SKU differs from normalized
- `catalog.identifier.gtin_invalid_length` (error) — GTIN length not 8/12/13/14
- `catalog.identifier.gtin_non_numeric` (error) — GTIN contains non-digits

#### Attribute Integrity

- `catalog.attribute.definition_tenant_mismatch` (critical) — Definition from different company
- `catalog.attribute.option_wrong_definition` (error) — Option from different definition
- `catalog.attribute.option_exists_for_non_select` (error) — Options for non-select type
- `catalog.attribute.product_value_tenant_mismatch` (critical) — Product value company mismatch
- `catalog.attribute.variant_value_tenant_mismatch` (critical) — Variant value company mismatch
- `catalog.attribute.scope_excludes_owner` (error) — Scope doesn't match owner type
- `catalog.attribute.multiple_typed_values` (error) — Multiple typed value fields filled
- `catalog.attribute.select_option_wrong_definition` (error) — Select option from different definition

#### Media Integrity

- `catalog.media.tenant_mismatch` (critical) — Media company differs from owner
- `catalog.media.product_media_wrong_product` (error) — Product media product_id mismatch
- `catalog.media.variant_media_wrong_variant` (error) — Variant media variant_id mismatch
- `catalog.media.primary_wrong_owner` (error) — Primary media points to wrong entity
- `catalog.media.missing_physical_file` (error) — DB row exists, file missing
- `catalog.media.invalid_mime` (warning) — MIME type not in allowlist

#### Lifecycle Integrity

- `catalog.lifecycle.active_product_blockers` (error) — Active product has blockers
- `catalog.lifecycle.archived_product_default_variant` (error) — Archived product's default variant not archived
- `catalog.lifecycle.invalid_status_value` (critical) — Unknown status value

#### Tenant Ownership

- `catalog.tenant.product_company_mismatch` (critical) — Cross-company denormalized relation
  - Checks all catalog tables for company_id mismatches between parent/child records

## Command Reference

### `catalog:integrity-check`

```
php artisan catalog:integrity-check {--company=} {--all-companies} {--format=table|json} {--severity=warning|error|critical} {--verify-files} {--verify-checksums} {--fail-on=warning|error|critical}
```

| Option | Default | Description |
|--------|---------|-------------|
| `--company=<uuid>` | (required) | Scan single company by UUID |
| `--all-companies` | — | Scan all companies (explicit flag required) |
| `--format` | `table` | Output format: `table` or `json` |
| `--severity` | `warning` | Minimum severity to display |
| `--verify-files` | — | Check physical file existence for media |
| `--verify-checksums` | — | Verify file checksums (expensive) |
| `--fail-on` | `error` | Exit code threshold |

**Exit codes:**

| Code | Meaning |
|------|---------|
| 0 | Scan completed, threshold not reached |
| 1 | Issues reached fail-on threshold |
| 2 | Invalid arguments/configuration |
| 3 | Scanner operational failure |

**Rules:**
- Must specify `--company` or `--all-companies` (not both)
- Read-only — no mutations
- No automatic repair

### `catalog:summary`

```
php artisan catalog:summary {--company=} {--all-companies} {--format=table|json} {--verify-files}
```

Displays catalog statistics:
- Categories count (active)
- Products by status (active, draft, archived)
- Variants by status
- Products missing primary category, default variant, primary media
- Products not ready
- Attribute definitions and options count
- Media count
- Missing physical files (if `--verify-files`)
- Stale drafts (drafts older than `catalog.operations.stale_draft_days`, default 90 days)

### `catalog:media-cleanup`

```
php artisan catalog:media-cleanup {--company=} {--all-companies} {--dry-run} {--execute} {--older-than=24} {--limit=1000} {--format=table|json}
```

| Option | Default | Description |
|--------|---------|-------------|
| `--company=<uuid>` | (required) | Scan single company |
| `--all-companies` | — | Scan all companies |
| `--dry-run` | (default) | Report only, no deletion |
| `--execute` | — | Perform actual deletion |
| `--older-than` | `24` | Minimum file age in hours |
| `--limit` | `1000` | Max files to process |
| `--format` | `table` | Output format |

**Rules:**
- Dry-run is the default mode
- `--execute` must be explicitly specified for deletion
- `--dry-run` and `--execute` are mutually exclusive
- Referenced files (with DB records) are never deleted
- Protected paths are never deleted
- Path traversal attacks are prevented
- Deletion happens after commit safety verification

## JSON Output

All commands support `--format=json` for machine-readable output.

### Integrity JSON

```json
{
  "summary": {
    "companies_scanned": 1,
    "issues_total": 5,
    "info": 0,
    "warning": 2,
    "error": 2,
    "critical": 1
  },
  "issues": [...]
}
```

### Summary JSON

```json
[{
  "company_uuid": "...",
  "company_name": "...",
  "categories_count": 10,
  "active_products": 25,
  ...
}]
```

### Cleanup JSON

```json
{
  "scanned": 150,
  "candidates": 12,
  "deleted": 10,
  "skipped": 2,
  "failed": 0,
  "bytes_reclaimed": 1048576,
  "failure_reasons": []
}
```

## Media Cleanup Safety

### Orphan Definitions

- **Database orphan**: File on disk with no ProductMedia row (including soft-deleted)
- **Physical-file orphan**: ProductMedia row exists but physical file is missing
- **Soft-deleted retained**: File still on disk past retention threshold
- **Protected**: Files in non-catalog directories or known protected paths

### Safety Invariants

1. **Re-verification**: Each file is re-checked against DB immediately before deletion
2. **Path guard**: `MediaPathGuard::assertSafeRelative()` validates all paths
3. **Path traversal blocked**: `..`, absolute paths, null bytes rejected
4. **Referenced files preserved**: Files with active DB references never deleted
5. **Age threshold**: Only files older than `--older-than` are candidates
6. **Company scope**: Only files within company's storage prefix
7. **Limit bounded**: Maximum batch size enforced
8. **Dry-run default**: No deletion without explicit `--execute`

## Scheduler

### Scheduled Tasks

| Task | Frequency | Locking | Type |
|------|-----------|---------|------|
| `catalog:integrity-check --all-companies --severity=critical --fail-on=critical` | Daily 06:00 UTC | `withoutOverlapping(120)` | Read-only |
| `catalog:summary --all-companies` | Daily 05:00 UTC | `withoutOverlapping(60)` | Read-only |
| `catalog:media-cleanup --all-companies --dry-run --older-than=168` | Weekly Sunday 03:00 UTC | `withoutOverlapping(120)` | Read-only (dry-run) |

### Safety Rules

- No automatic repair of integrity issues
- No automatic deletion of active media
- No automatic lifecycle changes
- No automatic identifier normalization
- Destructive cleanup must be run manually with `--execute`

## Structured Logging

### Operational Log Events

| Event | Fields |
|-------|--------|
| `catalog.integrity.completed` | companies_scanned, issues_total, severity_counts, duration |
| `catalog.integrity.failed` | error_message, company_uuid |
| `catalog.summary.completed` | companies_summarized, duration |
| `catalog.media_cleanup.completed` | scanned, deleted, skipped, failed, bytes_reclaimed, duration |
| `catalog.media_cleanup.failed` | error_message, company_uuid |

### Log Separation

- Business Audit Events: User changed a Catalog resource (immutable audit trail)
- Operational Logs: Scanner/cleanup command executed (system logs)
- These are separate systems — operational logs are NOT business audit events

## Runbook

### Daily Integrity Check

```bash
# Check scheduled output
tail storage/logs/scheduler-catalog-integrity.log

# Check specific company
php artisan catalog:integrity-check --company=<company-uuid> --format=json
```

### Investigating Critical Tenant Mismatch

```bash
# Full scan with JSON output
php artisan catalog:integrity-check --all-companies --severity=critical --format=json > integrity-report.json

# Review all critical issues
cat integrity-report.json | grep critical

# Verify specific company
php artisan catalog:integrity-check --company=<company-uuid> --severity=error --format=table
```

### Investigate Active Product Readiness Issue

```bash
# Check all products
php artisan catalog:integrity-check --company=<company-uuid> --severity=error --format=table
```

### Missing Media File

```bash
# Check with file verification
php artisan catalog:integrity-check --company=<company-uuid> --verify-files --format=json
```

### Dry-run Cleanup

```bash
# Report orphan files without deleting
php artisan catalog:media-cleanup --company=<company-uuid> --dry-run --older-than=168 --format=table

# JSON output for scripting
php artisan catalog:media-cleanup --company=<company-uuid> --dry-run --format=json
```

### Execute Cleanup

```bash
# CAUTION: Only on test/staging. Backup storage first.
php artisan catalog:media-cleanup --company=<company-uuid> --execute --older-than=168 --limit=500
```

### Partial Cleanup Failure

1. Review `failure_reasons` in JSON output
2. Check filesystem permissions on failed files
3. Re-run cleanup — already-deleted files are skipped
4. If persistent failures, investigate disk/storage configuration

### Check Scheduler

```bash
php artisan schedule:list
```

Verify catalog entries are present and not overdue.

### Structured Logs

```bash
# Filter for catalog operational events
grep "catalog.integrity\|catalog.summary\|catalog.media_cleanup" storage/logs/laravel.log
```

### Escalation Criteria

- Critical tenant ownership mismatch found → Manual investigation required
- Active product with readiness blockers → Check product configuration
- Missing primary media for active product → Restore from backup or re-upload
- Cleanup failure rate > 10% → Check storage health
- Scheduler task not running for > 24h → Check queue/cron health

### Before Manual Repair

1. Create database backup: `php artisan nordipass:backup --database-only`
2. Run integrity check to document pre-repair state
3. Execute repair via existing Catalog Actions (not raw SQL)
4. Re-run integrity check to verify resolution
5. Document the repair in operations log

## Performance

- Integrity scanner uses chunked queries (batch size: 500 rows)
- All-companies scan iterates companies with `chunkById()`
- Media scan uses bounded batches
- Cleanup limit enforceable via config
- Summary uses aggregate queries where possible

## Configuration

All operations configuration in `config/catalog.php` under `operations` key:

```php
'operations' => [
    'stale_draft_days' => 90,
    'integrity_batch_size' => 500,
    'cleanup_min_age_hours' => 24,
    'cleanup_max_batch_size' => 1000,
    'summary_batch_size' => 500,
],
```

## MySQL-Only Testing

All operations tests run on MySQL only. SQLite is not supported.

Test profiles:
- `tests/Feature/Catalog/Operations/CatalogIntegrityTest.php` — 18 integrity scanner tests
- `tests/Feature/Console/Catalog/CatalogCommandsTest.php` — 18 console command tests
- `tests/Unit/Catalog/Operations/IntegrityDataTypeTest.php` — 8 data object tests

## Deferred Repair Automation

R1.12 does NOT include automatic repair functionality. The following are intentionally deferred:

- Automatic integrity issue repair
- Automatic stale draft archiving
- Automatic lifecycle transitions
- Automatic identifier normalization
- Automatic default variant reassignment
- Automatic primary pointer repair
- Generic `catalog:repair` command
- Admin repair dashboard UI

All repairs must be performed manually using existing Catalog Actions until a future approved repair automation stage.

## Tenant Origin Scanning

When scanning with `--all-companies`, the scanner uses `chunkById()` on the Company model. Each company's data is checked in isolation. Cross-company issues are flagged as critical.

When scanning with `--company=<uuid>`, only issues belonging to that company are reported. Data from other companies is not exposed.

## Command File Reference

| File | Class | Purpose |
|------|-------|---------|
| `app/Console/Commands/Catalog/CatalogIntegrityCheckCommand.php` | `CatalogIntegrityCheckCommand` | Integrity scanner CLI |
| `app/Console/Commands/Catalog/CatalogSummaryCommand.php` | `CatalogSummaryCommand` | Catalog statistics CLI |
| `app/Console/Commands/Catalog/CatalogMediaCleanupCommand.php` | `CatalogMediaCleanupCommand` | Safe media cleanup CLI |
| `app/Services/Catalog/Integrity/CatalogIntegrityScanner.php` | `CatalogIntegrityScanner` | Scanner orchestrator |
| `app/Services/Catalog/Integrity/Checks/*.php` | 8 check classes | Individual integrity checks |
| `app/Services/Catalog/Operations/CatalogSummaryService.php` | `CatalogSummaryService` | Statistics aggregation |
| `app/Services/Catalog/Operations/CatalogMediaOrphanScanner.php` | `CatalogMediaOrphanScanner` | Orphan detection |
| `app/Services/Catalog/Operations/CatalogMediaCleanupService.php` | `CatalogMediaCleanupService` | Safe cleanup execution |
