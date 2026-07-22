# ADR-R3-011 — R2 to R3 Migration Strategy

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None

---

## Context

R2 has 36 migrations establishing the schema. R3 will add 18+ new tables, modify existing tables, and add new columns. Migration strategy must ensure safe deployment, backward compatibility during rollout, and data integrity for existing R2 data.

## Decision

### Migration Principles

1. **All migrations MySQL 8 compatible** — no SQLite, no PostgreSQL
2. **Non-destructive by default** — add columns, don't remove
3. **Expand → Backfill → Dual-write → Switch → Contract** pattern for complex changes
4. **No destructive schema removal in same deploy as new code**
5. **All migrations tested on fresh install AND R2 upgrade**

### Migration Phases

| Phase | Pattern | When |
|-------|---------|------|
| Expand | ADD COLUMN (nullable), CREATE TABLE | First deploy |
| Backfill | Fill new columns from existing data | After expand deploy |
| Dual-write | Write to both old and new structure | During rollout |
| Switch | Read from new structure | After dual-write verified |
| Contract | DROP old column/table | Following release |

### Planned Schema Changes by Stage

| Stage | Tables Affected | Change Type | Risk |
|-------|----------------|-------------|------|
| R3.2 | `passport_validation_runs` | Add `profile_code`, `profile_version` columns | Low — nullable additions |
| R3.3 | `product_passport_versions` | Extended JSON payload (schema v2) | Low — JSON expansion |
| R3.4 | `product_documents`, `product_document_versions` | Add `reviewed_by`, `approved_by`, `certificate_metadata` columns | Low — nullable additions |
| R3.5 | NEW: `category_templates`, `category_template_attributes`, `category_template_dpp_defaults` | New tables | Low — no existing data |
| R3.5 | `attribute_definitions` | Add `replaced_by_id`, `vocabulary_controlled` | Low — nullable additions |
| R3.6 | NEW: `import_jobs`, `import_records`, `import_errors` | New tables | Low — no existing data |
| R3.7 | NEW: `fortnox_connections`, `fortnox_article_mappings`, `fortnox_sync_logs` | New tables | Low — no existing data |
| R3.8 | NEW: `company_subscriptions`, `stripe_webhook_events`, `entitlement_overrides` | New tables | Low — no existing data |
| R3.9 | NEW: `analytics_events`, `analytics_daily`, `analytics_monthly` | New tables | Medium — write volume |
| R3.10 | `company_user` | Add more granular role options (new enum values) | Medium — existing roles must keep working |
| R3.10 | NEW: `approval_requests`, `approval_decisions`, `publication_policies` | New tables | Low — no existing data |
| R3.11 | None (frontend-only) | — | — |
| R3.12 | `product_passports` (config) | QR style preferences in `company_settings` JSON | Low — JSON expansion |
| R3.13 | NEW: `webhook_registrations`, `webhook_delivery_logs` | New tables | Low — no existing data |
| R3.14 | NEW: `notifications`, `notification_preferences`, `job_status` | New tables | Low — no existing data |
| R3.15 | None (observability on existing data) | — | — |
| R3.16 | `companies`, `users` | GDPR fields: `data_retention_months`, `data_deletion_requested_at` | Medium — nullable additions |
| R3.17 | None (observability configuration) | — | — |
| R3.18 | None (data seeding for onboarding) | — | — |
| R3.19 | None (verification) | — | — |

### R2 Data That Must Survive

| Data | Reason |
|------|--------|
| `companies` with their settings | Core tenant data |
| `company_user` memberships | User access |
| `users` with passwords | Authentication |
| `products`, `product_variants` | Product catalog |
| `categories` with hierarchy | Category tree |
| `attribute_definitions`, `attribute_options` | Attribute configurations |
| `product_attribute_values`, `variant_attribute_values` | Product data |
| `product_media` with files | Media library |
| `product_documents`, `product_document_versions` | Compliance documents |
| `product_passports` with public_id | Published passports must remain accessible |
| `product_passport_versions` (published) | Immutable published snapshots |
| `product_passport_assets` | Immutable published assets |
| `passport_validation_runs`, `passport_validation_results` | Historical readiness data |
| `activity_log` | Audit trail |
| `personal_access_tokens` | API integrations |

### Rollback/Fix-Forward Policy

- Schema additions (new columns, new tables): rollback migration available
- Data migrations (backfills): forward-fix only (write a new migration, don't rollback data)
- Renamed columns: use dual-write period, then switch
- Removed columns: contract in later release, never in same deploy

### Verification Queries

After each migration, verify:
```sql
-- No orphan records in any tenant-scoped table
SELECT COUNT(*) FROM products WHERE company_id NOT IN (SELECT id FROM companies);

-- Published passport public IDs are unique globally
SELECT public_id, COUNT(*) FROM product_passports WHERE status = 'published' GROUP BY public_id HAVING COUNT(*) > 1;

-- All passport versions have valid status
SELECT id, status FROM product_passport_versions WHERE status NOT IN ('draft', 'published', 'superseded', 'withdrawn');
```

## Consequences

- All R3 migrations are forward-compatible with R2 data
- Rollback is safe within each R3 stage's migration
- R2 published passports and QR codes continue working throughout R3 development
- CI must run full migration test (fresh + R2 upgrade) per R3 stage
