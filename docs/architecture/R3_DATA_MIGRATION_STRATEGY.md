# R3 — Data Migration Strategy

## Status: R3.1 Architecture Lock — APPROVED

---

## 1. Migration Principles

1. **All migrations MySQL 8 compatible** — no SQLite, no PostgreSQL dialect
2. **Non-destructive by default** — add, don't remove
3. **Expand → Backfill → Dual-write → Switch → Contract** for complex changes
4. **Never destructive schema removal in same deploy as new code**
5. **All migrations tested on fresh install AND R2 upgrade**
6. **All migrations use explicit column types** (no `->json()` without thinking about query patterns)

---

## 2. Migration Map: R2 → R3

| Stage | Migration | Tables/Columns | Method | Lock Risk | Rollback |
|-------|-----------|---------------|--------|-----------|----------|
| R3.2 | `add_readiness_profile_tracking` | `passport_validation_runs`: add `profile_code` VARCHAR(80), `profile_version` INT | ALTER TABLE ADD COLUMN (nullable) | Low | Drop column |
| R3.2 | `add_readiness_profile_assignments` | NEW: `readiness_profile_assignments` | CREATE TABLE | None | Drop table |
| R3.3 | `add_dpp_schema_v2_fields` | `product_passport_versions`: payload JSON expansion (no schema change, app-level) | None (JSON expansion) | None | N/A |
| R3.4 | `add_document_review_fields` | `product_documents`: add `reviewed_by`, `reviewed_at`, `approved_by`, `approved_at` | ALTER TABLE ADD COLUMN (nullable) | Low | Drop column |
| R3.4 | `add_certificate_metadata` | `product_document_versions`: add `certificate_metadata` JSON | ALTER TABLE ADD COLUMN (nullable) | Low | Drop column |
| R3.5 | `create_category_templates` | NEW: `category_templates`, `category_template_attributes`, `category_template_dpp_defaults` | CREATE TABLE | None | Drop table |
| R3.5 | `add_attribute_deprecation` | `attribute_definitions`: add `replaced_by_id` FK, `vocabulary_controlled` BOOL | ALTER TABLE ADD COLUMN (nullable) | Low | Drop column |
| R3.6 | `create_import_tables` | NEW: `import_jobs`, `import_records`, `import_errors` | CREATE TABLE | None | Drop table |
| R3.7 | `create_fortnox_tables` | NEW: `fortnox_connections`, `fortnox_article_mappings`, `fortnox_sync_logs` | CREATE TABLE | None | Drop table |
| R3.8 | `create_billing_tables` | NEW: `company_subscriptions`, `stripe_webhook_events`, `entitlement_overrides` | CREATE TABLE | None | Drop table |
| R3.9 | `create_analytics_tables` | NEW: `analytics_events`, `analytics_daily`, `analytics_monthly` | CREATE TABLE with partitioning consideration | None | Drop table |
| R3.10 | `create_approval_tables` | NEW: `approval_requests`, `approval_decisions`, `publication_policies` | CREATE TABLE | None | Drop table |
| R3.10 | `extend_company_roles` | `company_user.role`: add new enum values | ALTER TABLE MODIFY COLUMN (add enum values) | Medium* | Forward-fix only |
| R3.11 | None | (Frontend changes only) | — | — | — |
| R3.12 | `add_qr_preferences` | `company_settings` JSON expansion (no schema change) | None | None | N/A |
| R3.13 | `create_webhook_tables` | NEW: `webhook_registrations`, `webhook_delivery_logs` | CREATE TABLE | None | Drop table |
| R3.14 | `create_notification_tables` | NEW: `notifications`, `notification_preferences` | CREATE TABLE | None | Drop table |
| R3.14 | `create_job_status_table` | NEW: `job_status` (long-running job tracking) | CREATE TABLE | None | Drop table |
| R3.15 | None | (Observability on existing data, no new schema) | — | — | — |
| R3.16 | `add_gdpr_fields` | `companies`: add `data_retention_months`, `data_deletion_requested_at` | ALTER TABLE ADD COLUMN (nullable) | Low | Drop column |
| R3.16 | `add_user_privacy_fields` | `users`: add `data_deletion_requested_at`, `data_export_requested_at` | ALTER TABLE ADD COLUMN (nullable) | Low | Drop column |
| R3.17 | None | (Observability configuration, no schema changes needed) | — | — | — |
| R3.18 | None | (Seed data, no schema changes) | — | — | — |

*MySQL 8 can add enum values online without table copy. Verify with `ALGORITHM=INSTANT` where possible.

---

## 3. Data That Must Survive R2 → R3 Upgrade

| Table | Data | Protection |
|-------|------|------------|
| `companies` | All company records | No destructive migration |
| `company_user` | All memberships | Add-only changes |
| `users` | All user accounts | No destructive migration |
| `products` | All product catalog data | No destructive migration |
| `product_variants` | All variant data | No destructive migration |
| `categories` | All category data with hierarchy | No destructive migration |
| `attribute_definitions` | All attribute definitions | Add columns only |
| `product_media` | All media records + files | No destructive migration |
| `product_documents` | All document records | Add columns only |
| `product_passports` | All passports with public_id | Immutable columns protected |
| `product_passport_versions` | All published versions | Immutability enforced |
| `product_passport_assets` | All published assets | Immutability enforced |
| `passport_validation_runs` | All historical runs | Add columns only |
| `activity_log` | Full audit trail | No changes |
| `personal_access_tokens` | All API tokens | No changes |

---

## 4. Backward Compatibility During Rollout

| Old Code Dependency | R3 Change | Compatibility Method |
|--------------------|-----------|---------------------|
| R2 reads `passport_validation_runs` without profile fields | R3.2 adds `profile_code`, `profile_version` | New columns nullable, old code ignores them |
| R2 reads `product_documents` without review fields | R3.4 adds review columns | New columns nullable, old code ignores them |
| R2 uses old enum values for roles | R3.10 adds new enum values | Old values unchanged, new values additive |
| R2 publishes passport with schema v1 | R3.3 supports schema v2 | Both schemas valid, version recorded |
| R2 QR codes encode `/p/{public_id}` | R3.12 adds QR options | URL format unchanged |

---

## 5. Verification

After each R3 stage deployment:

```bash
# Fresh install test
php artisan migrate:fresh --env=testing
php artisan test

# R2 upgrade test
git stash
git checkout c9f0794  # R2 baseline
php artisan migrate:fresh --env=testing
php artisan nordipass:demo:seed --env=testing
git checkout -
php artisan migrate --env=testing
php artisan test

# Orphan record check
php artisan catalog:integrity-check --all-companies --severity=critical

# Public passport check
# Verify R2-published passports still resolve at their /p/{public_id} URLs
```
