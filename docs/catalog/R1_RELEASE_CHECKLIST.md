# NordiPass R1 — Release Checklist

**Release:** R1 Core Catalog
**Date:** 2026-07-16
**Target:** Production deployment

---

This checklist covers all steps required to deploy R1 Core Catalog to production. Complete each section in order. Check off items as they are verified.

---

## 1. Pre-deployment

### 1.1 System Requirements

- [ ] **PHP 8.4** installed and active (`php -v` shows 8.4.x)
- [ ] **MySQL 8.0** installed and running (`mysql --version` shows 8.0.x)
- [ ] **Node 20+** installed (`node -v` shows v20.x or v22.x)
- [ ] Required PHP extensions enabled:
  - [ ] `mbstring`
  - [ ] `pdo`
  - [ ] `pdo_mysql`
  - [ ] `fileinfo`
  - [ ] `gd` or `imagick` (image processing for media uploads)
- [ ] `php -m` confirms all extensions are loaded

### 1.2 Application Setup

- [ ] `git pull` latest from `master` branch
- [ ] `git log -1` confirms expected commit hash
- [ ] `composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev`
- [ ] `npm ci` completes without errors
- [ ] `npm run build` completes without errors
- [ ] `.env` file exists with production values (copy from `.env.example` if new)

### 1.3 Environment Configuration

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL` set to production domain
- [ ] `DB_CONNECTION=mysql`
- [ ] `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` configured for production database
- [ ] `php artisan key:generate` generates `APP_KEY` (if not already set)
- [ ] Verify `APP_KEY` is present and non-empty

### 1.4 Storage Permissions

- [ ] `storage/` directory writable by web server
- [ ] `bootstrap/cache/` directory writable by web server
- [ ] Catalog media storage directory exists and is writable (see §5)

---

## 2. Database

### 2.1 Migration

- [ ] Create production database if not exists
- [ ] Verify database credentials: `php artisan db:show` or manual connection test
- [ ] Run migrations: `php artisan migrate --force`
- [ ] Verify output shows all 24 migrations applied successfully (no errors, no warnings)

### 2.2 Migration Verification

- [ ] 12 R1 catalog migrations applied (000001–000012):
  - [ ] `2026_07_14_000001_create_categories_table`
  - [ ] `2026_07_14_000002_create_products_table`
  - [ ] `2026_07_14_000003_create_product_variants_table`
  - [ ] `2026_07_14_000004_create_category_product_table`
  - [ ] `2026_07_14_000005_create_attribute_definitions_table`
  - [ ] `2026_07_14_000006_create_attribute_options_table`
  - [ ] `2026_07_14_000007_create_product_attribute_values_table`
  - [ ] `2026_07_14_000008_create_variant_attribute_values_table`
  - [ ] `2026_07_14_000009_create_product_attribute_value_options_table`
  - [ ] `2026_07_14_000010_create_variant_attribute_value_options_table`
  - [ ] `2026_07_14_000011_create_product_media_table`
  - [ ] `2026_07_14_000012_add_catalog_deferred_foreign_keys`
- [ ] 12 R0 foundation migrations applied (000000–230000)

### 2.3 Foreign Key Verification

- [ ] Run the following SQL (adjust for your database name) to verify all catalog FKs exist:

```sql
SELECT TABLE_NAME, CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'your_database'
  AND REFERENCED_TABLE_NAME IS NOT NULL
  AND TABLE_NAME IN (
    'categories', 'products', 'product_variants', 'category_product',
    'attribute_definitions', 'attribute_options',
    'product_attribute_values', 'variant_attribute_values',
    'product_attribute_value_options', 'variant_attribute_value_options',
    'product_media'
  )
ORDER BY TABLE_NAME;
```

- [ ] 32 foreign keys confirmed (11 simple + 21 composite)
- [ ] No orphan foreign keys or missing referenced tables

### 2.4 Seeding

- [ ] Determine if seeding is needed:
  - **New installation:** `php artisan db:seed --force` (includes catalog demo data)
  - **Existing installation:** Skip if data already exists; only seed if environment requires demo data
- [ ] If seeding catalog demo data: verify `CatalogDemoSeeder` completes without errors

---

## 3. Configuration

### 3.1 Laravel Cache

- [ ] `php artisan config:cache` — config cached successfully
- [ ] `php artisan route:cache` — routes cached successfully
- [ ] `php artisan view:cache` — views cached successfully
- [ ] Verify `bootstrap/cache/config.php` exists and is recent
- [ ] Verify `bootstrap/cache/routes-v7.php` exists

### 3.2 Catalog-Specific Configuration

- [ ] Open `config/catalog.php` (or verify via env variables):
  - [ ] `operations.stale_draft_days` set appropriately (default: 90)
  - [ ] `operations.integrity_batch_size` set (default: 500)
  - [ ] `operations.cleanup_min_age_hours` set (default: 24)
  - [ ] `operations.cleanup_max_batch_size` set (default: 1000)
  - [ ] `operations.summary_batch_size` set (default: 500)
- [ ] Catalog media disk configuration verified (see §5)
- [ ] Audit retention configuration verified:
  - [ ] `audit.retention_days` set (default: 365)
  - [ ] `nordipass:prune-audit-logs` scheduled for daily execution

---

## 4. Validation

### 4.1 Test Suite

- [ ] `php artisan test` — full test suite runs
- [ ] All tests pass (0 failures, 0 errors)
- [ ] Tests run on MySQL `nordipass_testing` database (not SQLite)
- [ ] Review test output for any warnings or deprecation notices

### 4.2 Static Analysis

- [ ] `php vendor/bin/phpstan analyse --no-progress` completes
- [ ] Result: **0 errors**
- [ ] No baseline exceptions or ignored errors for catalog code

### 4.3 Code Style

- [ ] `php vendor/bin/pint --test` completes
- [ ] Result: all files pass, no style violations

### 4.4 Route Verification

- [ ] `php artisan route:list --path=catalog` shows all Web catalog routes
- [ ] Verify expected Web routes present:
  - [ ] `catalog.products.index`, `.create`, `.store`, `.show`, `.edit`, `.update`
  - [ ] `catalog.products.variants.*` (all variant routes)
  - [ ] `catalog.audit.index`, `catalog.audit.show`
  - [ ] Category routes under settings prefix
  - [ ] Lifecycle routes (activate, archive, restore, etc.)
- [ ] `php artisan route:list --path=api/v1/catalog` shows all API catalog routes
- [ ] Verify 53 API routes present under `/api/v1/catalog`
- [ ] All routes have correct middleware groups (auth, verified, company, etc.)

### 4.5 Schedule Verification

- [ ] `php artisan schedule:list` shows all scheduled tasks
- [ ] Verify catalog-specific entries are present:
  - [ ] `catalog:integrity-check --all-companies --severity=critical --fail-on=critical` (Daily 06:00)
  - [ ] `catalog:summary --all-companies` (Daily 05:00)
  - [ ] `catalog:media-cleanup --all-companies --dry-run --older-than=168` (Weekly Sunday 03:00)
- [ ] Verify existing R0 scheduled tasks still present (backups, audit pruning, etc.)

---

## 5. Media Storage

### 5.1 Disk Configuration

- [ ] Catalog media disk configured in `config/filesystems.php` under `disks.catalog_media`:
  ```php
  'catalog_media' => [
      'driver' => 'local',
      'root' => env('CATALOG_MEDIA_ROOT', storage_path('app/catalog-media')),
      'throw' => true,
      'visibility' => 'private',
  ],
  ```
- [ ] **Local storage:** Directory exists, web server has read/write permissions
- [ ] **S3 storage (if used):** S3 credentials configured, bucket exists, appropriate IAM permissions, private ACL by default

### 5.2 Environment Variable

- [ ] `CATALOG_MEDIA_ROOT` set in `.env` (or uses default `storage/app/catalog-media`)
- [ ] For S3: `FILESYSTEM_DISK=s3` and all `AWS_*` variables configured

### 5.3 Storage Visibility

- [ ] All catalog media files must be **private** (not publicly accessible)
- [ ] Verify the storage root is outside the web document root (for local storage, default `storage/app/` is not web-accessible)
- [ ] Authenticated media delivery uses `catalog.media.content` route — verify this route streams files correctly

### 5.4 Test Upload

- [ ] Upload a test image via Web UI (`/catalog/products/{product}/media`)
- [ ] Verify file appears in storage at expected path: `{company_uuid}/products/{product_uuid}/{media_uuid}.{ext}`
- [ ] Verify image displays correctly on product detail page
- [ ] Verify image is NOT accessible via direct URL (returns 404 or auth redirect)
- [ ] Upload a variant image — verify path includes variant UUID
- [ ] Delete test media — verify record soft-deleted and file remains (orphan cleanup handles later)

---

## 6. Scheduler

### 6.1 Cron Configuration

- [ ] Cron entry configured on server:
  ```
  * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
  ```
- [ ] Verify cron daemon is running: `systemctl status cron` or `ps aux | grep cron`
- [ ] Verify scheduler heartbeat (if configured) reports recent execution

### 6.2 Catalog Scheduled Tasks

- [ ] Daily integrity check configured and running:
  - [ ] Task: `catalog:integrity-check --all-companies --severity=critical --fail-on=critical`
  - [ ] Schedule: Daily at 06:00 UTC
  - [ ] Lock: `withoutOverlapping(120)` — prevents concurrent runs
  - [ ] First run: manually trigger with `php artisan catalog:integrity-check --all-companies --severity=critical` to verify output
- [ ] Daily summary configured and running:
  - [ ] Task: `catalog:summary --all-companies`
  - [ ] Schedule: Daily at 05:00 UTC
  - [ ] Lock: `withoutOverlapping(60)`
  - [ ] First run: manually trigger to verify output
- [ ] Weekly media cleanup dry-run configured:
  - [ ] Task: `catalog:media-cleanup --all-companies --dry-run --older-than=168`
  - [ ] Schedule: Weekly Sunday at 03:00 UTC
  - [ ] Lock: `withoutOverlapping(120)`
  - [ ] **Important:** Dry-run only — no files are deleted by the scheduler. Manual `--execute` required for actual cleanup.
- [ ] Verify existing R0 scheduled tasks unaffected (backups, audit pruning, etc.)

### 6.3 Scheduler Logging

- [ ] Scheduler output logged (configurable in `config/catalog.php` or application logging config)
- [ ] Verify log rotation configured for scheduler output files
- [ ] Test: run `php artisan schedule:run` manually, verify no errors in scheduler output

---

## 7. API Tokens

### 7.1 Token Generation

- [ ] Identify integrations requiring API access (external systems, scripts, services)
- [ ] For each integration, create a Personal Access Token via `php artisan tinker` or admin UI:
  ```php
  $company = \App\Models\Company::where('uuid', '...')->first();
  $user = \App\Models\User::where('email', '...')->first();
  $token = $user->createToken(
      'Integration Name',
      ['catalog.read', 'catalog.write', 'catalog.lifecycle', 'catalog.media'],
      $company
  );
  echo $token->plainTextToken;
  ```
- [ ] Assign only necessary abilities to each token (principle of least privilege):
  - [ ] Read-only access: `['catalog.read']`
  - [ ] Product management: `['catalog.read', 'catalog.write']`
  - [ ] Lifecycle operations: add `'catalog.lifecycle'`
  - [ ] Media operations: add `'catalog.media'`

### 7.2 Token Ability Reference

| Ability | Token String | Use Case |
|---|---|---|
| Read | `catalog.read` | Dashboards, reporting, search integrations |
| Write | `catalog.write` | Product sync, attribute management, category management |
| Lifecycle | `catalog.lifecycle` | Automated activation/deactivation, archive workflows |
| Media | `catalog.media` | Image upload services, media management tools |

### 7.3 Token Security

- [ ] Set expiration dates on tokens (no perpetual tokens for production)
- [ ] Document each token's purpose, assigned company, abilities, and expiration
- [ ] Store plain-text tokens securely (password manager, secrets vault)
- [ ] Never commit tokens to version control or include in configuration files
- [ ] Revoke unused tokens: `php artisan sanctum:prune-expired`
- [ ] Verify token validation: `POST /api/v1/catalog/products` with `Authorization: Bearer <token>`

---

## 8. Monitoring

### 8.1 Scheduler Heartbeat

- [ ] Configure scheduler heartbeat monitoring (e.g., Laravel Pulse, external cron monitoring service)
- [ ] Verify scheduler runs at least once per minute
- [ ] Set alerts for missed scheduler runs (> 5 minutes without execution)

### 8.2 Readiness Endpoint

- [ ] Verify `GET /ready` endpoint responds with 200 (or your configured health check path)
- [ ] Health check includes:
  - [ ] Database connectivity
  - [ ] Cache connectivity (if applicable)
  - [ ] Storage writability (if configured)
- [ ] External monitoring service configured to poll readiness endpoint

### 8.3 Structured Logging

- [ ] Verify structured logs are written for catalog events:
  - [ ] Business audit events: `catalog.product.created`, `catalog.category.moved`, etc.
  - [ ] Operational logs: `catalog.integrity.completed`, `catalog.summary.completed`, `catalog.media_cleanup.completed`
- [ ] Check log file paths:
  - [ ] `storage/logs/laravel.log` — application logs
  - [ ] `storage/logs/scheduler-catalog-integrity.log` — integrity check output
  - [ ] `storage/logs/scheduler-catalog-summary.log` — summary output
- [ ] Verify log aggregation/forwarding if using centralized logging (ELK, Datadog, etc.)
- [ ] Test: trigger a catalog action, verify audit event appears in logs

### 8.4 Error Monitoring

- [ ] Error tracking service configured (Sentry, Flare, Bugsnag, etc.)
- [ ] Verify catalog-related exceptions are captured with full context
- [ ] Configure alert thresholds for catalog error spikes

---

## 9. Backup

### 9.1 Pre-deployment Backup

- [ ] `php artisan nordipass:backup` runs successfully before first R1 deploy
- [ ] **This includes:**
  - [ ] Database dump (full, all tables)
  - [ ] Storage files (media, uploads)
  - [ ] Environment configuration (`.env`)
- [ ] Verify backup file exists and is non-empty
- [ ] `php artisan nordipass:backup --verify` — backup integrity verification passes

### 9.2 Backup Retention

- [ ] Verify backup retention policy configured (default: keep last 7 daily, 4 weekly, 12 monthly)
- [ ] Verify backup storage location is separate from application server (S3, separate disk, off-site)
- [ ] Test restore procedure documented and accessible to operations team

### 9.3 Rollback Plan

- [ ] Document rollback procedure in case of deployment failure:
  1. Restore pre-R1 database backup
  2. Restore pre-R1 storage files
  3. Checkout previous commit
  4. Run `composer install` and `npm ci && npm run build`
  5. Clear caches: `php artisan optimize:clear`
  6. Verify application health
- [ ] Rollback procedure tested in staging environment

---

## 10. Post-deployment

### 10.1 Initial Setup

- [ ] Log in to production application
- [ ] Create first Company (if new installation) or verify existing Company
- [ ] Assign user roles:
  - [ ] At least one **Owner** (full access)
  - [ ] At least one **Admin** (catalog management)
  - [ ] At least one **Editor** (content operations)
  - [ ] At least one **Viewer** (read-only)
- [ ] Verify role assignments in database (`company_user` table)

### 10.2 Smoke Tests — Web UI

- [ ] Log in as Owner/Admin, navigate to `/settings/catalog/categories`
- [ ] Create a test Category (e.g., "Test Category")
- [ ] Update the Category name and slug
- [ ] Create a child Category under the test Category
- [ ] Move the child Category to root
- [ ] Reorder sibling Categories
- [ ] Archive the child Category
- [ ] Restore the child Category
- [ ] Navigate to `/catalog/products`
- [ ] Create a test Product:
  - [ ] Set name, slug, brand, manufacturer
  - [ ] Assign primary Category
  - [ ] Assign additional Categories
  - [ ] Set short description
  - [ ] Verify default Variant auto-created with name "Default"
- [ ] Edit the test Product — change fields, verify changes persist
- [ ] Add a second Variant to the test Product:
  - [ ] Set SKU (e.g., `TEST-SKU-001`)
  - [ ] Set GTIN (valid, e.g., `7312345678901`)
  - [ ] Set MPN
  - [ ] Set Variant name
- [ ] Set the second Variant as default
- [ ] Verify the original Variant is no longer default
- [ ] Check Product readiness: `GET /catalog/products/{product}`
- [ ] Activate the Product
- [ ] Verify Product appears in active product list
- [ ] Return Product to draft
- [ ] Archive the Product
- [ ] Restore the Product (should return to draft)
- [ ] Add an Attribute Definition (e.g., "Color", type: text)
- [ ] Assign attribute value to the Product
- [ ] Upload a product image (JPEG or PNG)
- [ ] Set uploaded image as primary
- [ ] Verify image displays on product detail page
- [ ] Navigate to `/catalog/audit`
- [ ] Verify audit events appear for all test operations
- [ ] Filter audit by event type, verify results

### 10.3 Smoke Tests — API

```bash
# Replace <token> with a valid API token
BASE="https://your-domain.com/api/v1/catalog"
TOKEN="your-api-token"

# Test: List products
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "$BASE/products" | head -20

# Test: Create a category
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"API Test Category","slug":"api-test-category"}' \
  "$BASE/categories"

# Test: List categories
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "$BASE/categories"

# Test: Create a product
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name":"API Test Product","slug":"api-test-product"}' \
  "$BASE/products"

# Test: Search products
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "$BASE/products?q=Test"
```

- [ ] All API smoke tests return 200/201 responses
- [ ] Response format matches OpenAPI 3.1 specification
- [ ] `X-Request-ID` header present in all responses
- [ ] Verify error responses for invalid requests (422, 404, 403)

### 10.4 Authorization Verification — API

- [ ] Test with token lacking required ability — verify 403 with `token_ability_missing`
- [ ] Test with token for wrong company — verify 404 (resource not found)
- [ ] Test with revoked token — verify 401 with `unauthenticated`
- [ ] Test with expired token — verify 401
- [ ] Test rate limits — after 120+ read requests in 1 minute, verify 429 with `rate_limited`

### 10.5 Clean Up Test Data

- [ ] Remove or archive test Categories, Products, Variants, and Attributes created during smoke testing
- [ ] Remove test media uploads
- [ ] Verify no test data remains in production

---

## 11. Sign-off

### Completion Verification

- [ ] All items in sections 1–10 checked and verified
- [ ] No failed or skipped items without documented justification
- [ ] Smoke tests completed successfully (Web and API)
- [ ] Test data cleaned up
- [ ] Monitoring configured and alerting
- [ ] Backup verified

### Sign-off Record

| Field | Value |
|---|---|
| **Date** | |
| **Reviewer Name** | |
| **Reviewer Role** | |
| **Signature** | |
| **Notes** | |

---

## Emergency Contacts

| Role | Name | Contact |
|---|---|---|
| Lead Developer | | |
| DevOps / Infrastructure | | |
| Product Owner | | |
| On-call Engineer | | |

---

## References

- **Quality review:** [R1_FINAL_QUALITY_REVIEW.md](R1_FINAL_QUALITY_REVIEW.md)
- **Release notes:** [R1_RELEASE_NOTES.md](R1_RELEASE_NOTES.md)
- **Scope:** [R1_CATALOG_SCOPE.md](R1_CATALOG_SCOPE.md)
- **API documentation:** [CATALOG_API.md](CATALOG_API.md)
- **Operations:** [CATALOG_OPERATIONS.md](CATALOG_OPERATIONS.md)
- **CI configuration:** `.github/workflows/ci.yml`
