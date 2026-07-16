# Catalog Audit

## Scope

This document describes the Catalog Audit architecture for NordiPass R1.12. Catalog audit records every mutation to catalog resources (Categories, Products, Variants, Attributes, Options, Media) via immutable append-only event records.

## Event Taxonomy

All catalog audit events follow the naming pattern `catalog.<resource>.<action>`:

### Categories

| Event | Description |
|-------|-------------|
| `catalog.category.created` | Category created |
| `catalog.category.updated` | Category fields updated |
| `catalog.category.moved` | Category moved to different parent |
| `catalog.category.reordered` | Sibling categories reordered |
| `catalog.category.archived` | Category archived |
| `catalog.category.restored` | Category restored from archive |

### Products

| Event | Description |
|-------|-------------|
| `catalog.product.created` | Product created with default variant |
| `catalog.product.updated` | Product fields updated |
| `catalog.product.activated` | Product lifecycle: Draft -> Active |
| `catalog.product.returned_to_draft` | Product lifecycle: Active -> Draft |
| `catalog.product.archived` | Product lifecycle: any -> Archived |
| `catalog.product.restored` | Product lifecycle: Archived -> Draft |

### Variants

| Event | Description |
|-------|-------------|
| `catalog.variant.created` | Variant created under product |
| `catalog.variant.updated` | Variant fields updated |
| `catalog.variant.default_changed` | Product default variant changed |
| `catalog.variant.archived` | Variant archived |
| `catalog.variant.restored` | Variant restored |

### Attribute Definitions

| Event | Description |
|-------|-------------|
| `catalog.attribute.created` | Attribute definition created |
| `catalog.attribute.updated` | Attribute definition updated |
| `catalog.attribute.archived` | Attribute definition archived |
| `catalog.attribute.restored` | Attribute definition restored |

### Attribute Options

| Event | Description |
|-------|-------------|
| `catalog.attribute.option.created` | Attribute option created |
| `catalog.attribute.option.updated` | Attribute option updated |
| `catalog.attribute.option.archived` | Attribute option archived |
| `catalog.attribute.option.restored` | Attribute option restored |
| `catalog.attribute.options.reordered` | Options reordered |

### Attribute Values

| Event | Description |
|-------|-------------|
| `catalog.product.attributes.updated` | Product attribute values synced |
| `catalog.variant.attributes.updated` | Variant attribute values synced |

### Media

| Event | Description |
|-------|-------------|
| `catalog.media.uploaded` | Media uploaded |
| `catalog.media.updated` | Media metadata updated |
| `catalog.media.primary_changed` | Primary media changed |
| `catalog.media.reordered` | Media reordered |
| `catalog.media.deleted` | Media deleted |

> Note: Product and Variant media use the same event set. Product/Variant context is stored in metadata (`product_uuid`, `variant_uuid`).

## Coverage Matrix

All Catalog Actions emit audit events. Every mutation goes through the Action layer which centralizes audit emission.

| Action | Event | Actor | Source | Metadata |
|--------|-------|-------|--------|----------|
| CreateCategoryAction | `catalog.category.created` | User | web/api | category_uuid, parent_uuid |
| UpdateCategoryAction | `catalog.category.updated` | User | web/api | category_uuid, changed_fields |
| MoveCategoryAction | `catalog.category.moved` | User | web/api | category_uuid, old_parent_uuid, new_parent_uuid |
| ReorderSiblingCategoriesAction | `catalog.category.reordered` | User | web/api | parent_uuid, category_count |
| ArchiveCategoryAction | `catalog.category.archived` | User | web/api | category_uuid, status_before, status_after |
| RestoreCategoryAction | `catalog.category.restored` | User | web/api | category_uuid |
| CreateProductAction | `catalog.product.created` | User | web/api | product_uuid, category_uuids |
| UpdateProductAction | `catalog.product.updated` | User | web/api | product_uuid, changed_fields |
| ActivateProductAction | `catalog.product.activated` | User | web/api | product_uuid, activation_check |
| ReturnProductToDraftAction | `catalog.product.returned_to_draft` | User | web/api | product_uuid |
| ArchiveProductAction | `catalog.product.archived` | User | web/api | product_uuid |
| RestoreProductAction | `catalog.product.restored` | User | web/api | product_uuid |
| CreateProductVariantAction | `catalog.variant.created` | User | web/api | product_uuid, variant_uuid |
| UpdateProductVariantAction | `catalog.variant.updated` | User | web/api | product_uuid, variant_uuid, changed_fields |
| SetDefaultProductVariantAction | `catalog.variant.default_changed` | User | web/api | product_uuid, old_default_variant_uuid, new_default_variant_uuid |
| ArchiveProductVariantAction | `catalog.variant.archived` | User | web/api | product_uuid, variant_uuid |
| RestoreProductVariantAction | `catalog.variant.restored` | User | web/api | product_uuid, variant_uuid |
| CreateAttributeDefinitionAction | `catalog.attribute.created` | User | web/api | definition_uuid |
| UpdateAttributeDefinitionAction | `catalog.attribute.updated` | User | web/api | definition_uuid, changed_fields |
| ArchiveAttributeDefinitionAction | `catalog.attribute.archived` | User | web/api | definition_uuid |
| RestoreAttributeDefinitionAction | `catalog.attribute.restored` | User | web/api | definition_uuid |
| CreateAttributeOptionAction | `catalog.attribute.option.created` | User | web/api | definition_uuid, option_code |
| UpdateAttributeOptionAction | `catalog.attribute.option.updated` | User | web/api | definition_uuid, option_code, changed_fields |
| ArchiveAttributeOptionAction | `catalog.attribute.option.archived` | User | web/api | definition_uuid, option_code |
| RestoreAttributeOptionAction | `catalog.attribute.option.restored` | User | web/api | definition_uuid, option_code |
| ReorderAttributeOptionsAction | `catalog.attribute.options.reordered` | User | web/api | definition_uuid, option_count |
| SyncProductAttributeValuesAction | `catalog.product.attributes.updated` | User | web/api | product_uuid, changed_definition_uuids |
| SyncVariantAttributeValuesAction | `catalog.variant.attributes.updated` | User | web/api | product_uuid, variant_uuid, changed_definition_uuids |
| UploadProductMediaAction | `catalog.media.uploaded` | User | web/api | product_uuid, media_uuid |
| UploadVariantMediaAction | `catalog.media.uploaded` | User | web/api | product_uuid, variant_uuid, media_uuid |
| UpdateProductMediaAction | `catalog.media.updated` | User | web/api | media_uuid, changed_fields |
| SetPrimaryProductMediaAction | `catalog.media.primary_changed` | User | web/api | product_uuid, old_primary_media_uuid, new_primary_media_uuid |
| SetPrimaryVariantMediaAction | `catalog.media.primary_changed` | User | web/api | product_uuid, variant_uuid, old_primary_media_uuid, new_primary_media_uuid |
| ReorderProductMediaAction | `catalog.media.reordered` | User | web/api | product_uuid, media_count |
| ReorderVariantMediaAction | `catalog.media.reordered` | User | web/api | product_uuid, variant_uuid, media_count |
| DeleteProductMediaAction | `catalog.media.deleted` | User | web/api | product_uuid, media_uuid, was_primary |
| DeleteVariantMediaAction | `catalog.media.deleted` | User | web/api | product_uuid, variant_uuid, media_uuid, was_primary |

## Actor Contract

- **Authenticated User**: `actor_email` and `actor_name` stored in audit properties
- **System Actor**: `null` actor logged as anonymous, displayed as "System"
- **Console Actor**: `null` actor, source marker `console` or `scheduler` set
- **Scheduler Actor**: `null` actor, source marker `scheduler` set

No passwords, tokens, session IDs, or auth secrets are stored.

## Source Contract

Allowlisted sources:

| Source | When used |
|--------|-----------|
| `web` | Web UI requests |
| `api` | API v1 requests |
| `console` | Manual artisan commands |
| `scheduler` | Scheduled operations |
| `system` | Internal system operations |

Source is determined by the application context, NEVER from user input.

## Request ID

- Web/API audit events include `request_id` from `X-Request-ID` header
- The `EnsureRequestId` middleware generates or validates request IDs
- Console/Scheduler events may include `operation_run_id`
- Request IDs are bounded (max 100 chars) and validated against `[A-Za-z0-9._-]`

## Metadata Allowlist

Safe metadata fields included in audit events:

- `resource_uuid`, `parent_uuid`, `old_parent_uuid`, `new_parent_uuid`
- `old_status`, `new_status`
- `changed_fields` (field name list)
- `category_uuids` (array of category UUIDs)
- `default_variant_uuid`, `old_default_variant_uuid`, `new_default_variant_uuid`
- `attribute_definition_uuid`
- `option_uuids`
- `media_uuid`
- `product_uuid`, `variant_uuid`
- `source`, `request_id`

## Before/After Policy

For update events, only `changed_fields` names are stored (not full values). Full value diffs are stored only for company settings changes via the `changes` property.

## Transaction Behavior

- Audit events are created within the same DB transaction as the business mutation
- Failed mutations do not leave audit events
- Rolled-back transactions leave no audit records
- Authorization failures create no business audit events
- Wrong-tenant attempts create no business audit events

## Duplicate Prevention

- Web and API controllers call the same Actions - no duplicate events
- Controllers do not emit audit events directly
- Actions are single-responsibility and emit exactly one event per mutation
- Idempotent no-ops (archiving already-archived, restoring already-active) are documented

## Web/API Parity

Both Web and API controllers use identical Catalog Actions. The same audit events are emitted with identical event names. Only the source metadata differs (web vs api).

## Authorization

- `AuditView` permission required to view audit logs
- Granted to: Owner, Admin roles
- Denied to: Editor, Viewer roles
- Cross-company audit events return 404
- Listing is always tenant-scoped

## Audit UI

### Routes

- `GET /catalog/audit` (`catalog.audit.index`) — Catalog audit listing with filters
- `GET /catalog/audit/{auditEvent}` (`catalog.audit.show`) — Individual event details

### Filters

- Event type (select from catalog events)
- Actor UUID
- Resource type
- Resource UUID
- Request ID
- Date range (from/to, max 366 days)
- Keyword search (event name or request ID)
- Per-page: 25, 50, 100 (default 50)

### Views

- `resources/views/catalog/audit/index.blade.php` — Listing with filters
- `resources/views/catalog/audit/show.blade.php` — Detail view with safe metadata

## Retention

- Existing audit retention: 365 days (config `audit.retention_days`)
- `nordipass:prune-audit-logs` runs daily via Scheduler
- Only tenant logs are pruned; platform logs are never pruned
- No automatic audit cleanup beyond the existing policy
- Audit retention policy is deferred for R1.12 — no changes made

## Database Guarantees

- Application-level: append-only (no update/delete routes)
- Model-level: `updating` and `deleting` hooks throw LogicException
- Database-level: MySQL indexes on (company_id, created_at), (event, created_at), (causer_type, causer_id, created_at), (subject_type, subject_id, created_at), (request_id)
- FK: `company_id` -> `companies.id` with `nullOnDelete`

## Application Guarantees

- Audit events are immutable in the application
- No UI routes for editing or deleting audit history
- No API endpoints for audit mutations
- Audit records survive company deletion (FK set to null)

## Security

- `SensitiveDataSanitizer` strips passwords, tokens, hashes, secrets, and Bearer headers
- No credentials stored in audit properties
- No raw request body stored
- No storage paths stored
- No full model serializations
- Actor identified by public-safe identifier (email address, not display name only)

## MySQL Tests

All audit tests run on MySQL only (`_testing` database). SQLite is not used. Test profiles:

- `tests/Feature/Catalog/Audit/CatalogAuditUiTest.php` — UI access, filters, pagination, tenant isolation (15 tests)
- `tests/Feature/Catalog/Audit/CatalogAuditCoverageTest.php` — Every Action emits correct event (24 tests)
- `tests/Unit/Catalog/Audit/CatalogAuditQueryTest.php` — Query builder filters (9 tests)
- `tests/Feature/Audit/*` — Existing general audit tests (6 files)

## Deferred Items

- Automatic audit cleanup without pre-existing retention policy
- Public audit API endpoints
- Cryptographic immutability (blockchain/hash chain)
- Legal compliance certification
- Cross-region audit replication
- Immutable audit data export
