# NordiPass R1 — Release Notes

**Release:** R1 Core Catalog
**Version:** 1.0.0
**Date:** 2026-07-16
**Type:** Initial release

---

## 1. Overview

R1 delivers the **Core Catalog PIM (Product Information Management)** module for NordiPass, a multi-tenant SaaS platform. Companies can now manage their full product catalog — Products, Variants, Categories, Attributes, and Media — through both an intuitive Web interface and a comprehensive REST API.

The catalog is built on NordiPass R0 foundation (Company management, user invitations, role-based permissions, and immutable audit logging) and extends it with 12 new database tables, 38 Action classes, 53 API endpoints, and 8 catalog-specific permissions.

---

## 2. What's Included

- **Categories** — Hierarchical tree with adjacency list, depth-limited to 5 levels, with move, reorder, archive, and restore operations
- **Products** — Aggregate root with atomic creation (Product + default Variant in one transaction), slug uniqueness, primary and secondary Category assignments
- **Variants and Identifiers** — Mandatory variant-per-product model, SKU/GTIN/MPN identifiers with normalization and uniqueness enforcement
- **Attributes (EAV)** — Reusable typed attribute definitions with 7 data types, scope enforcement (product/variant/both), predefined options for select/multiselect
- **Media Management** — JPEG/PNG/WEBP image upload with SHA-256 checksums, private authenticated storage, primary image selection, reorder, UUID-based storage paths
- **Product Lifecycle** — Draft → Active → Archived state machine with 10-point publication readiness checklist (8 hard gates, 2 soft gates)
- **Advanced Search** — MySQL full-text and prefix search across name, SKU, GTIN, MPN, brand, manufacturer; filter by status, category, attributes, readiness, missing data
- **REST API** — 53 endpoints under `/api/v1/catalog`, Bearer token authentication with 4 granular abilities, OpenAPI 3.1 specification
- **Audit Trail** — 38 Actions producing 32 unique immutable audit events with complete coverage, dedicated audit UI with filters and detail views
- **Integrity Diagnostics** — 8 integrity checks scanning categories, products, variants, identifiers, attributes, media, lifecycle, and tenant ownership
- **Safe Media Cleanup** — Dry-run by default, explicit `--execute` required, path traversal protection, file re-verification before deletion

---

## 3. Key Features

### Categories with Tree Hierarchy

- Adjacency list model with `parent_id` self-referencing FK and computed `depth`
- Maximum depth of 5 (configurable via `CATALOG_MAX_CATEGORY_DEPTH`)
- Cycle detection and prevention (application-level + database triggers for self-parent)
- Move operation with full subtree depth adjustment in one transaction
- Sibling reorder with deterministic increment-based sort order (10, 20, 30...)
- Archive protection: category with active children or primary for active products is rejected
- Restore does not auto-restore parent relationship — explicit move required

### Products with Variants

- Every Product has at least one Variant (mandatory variant model)
- Atomic creation: Product + default Variant in a single database transaction
- Slug auto-generation from name with normalization and company-unique enforcement
- Primary Category via `products.primary_category_id` FK (required for active products)
- Additional Categories via `category_product` many-to-many pivot
- Brand and manufacturer as free-text fields

### SKU, GTIN, and MPN Identifiers

- **SKU:** Company-unique, case-insensitive, whitespace-stripped for uniqueness. Display SKU preserves original casing and spacing. Max 100 characters.
- **GTIN:** GTIN-8, GTIN-12 (UPC), GTIN-13 (EAN-13), GTIN-14. Digit-only with GS1 check digit validation. `UNIQUE(company_id, gtin)` — MySQL allows multiple NULLs.
- **MPN:** Free-text, trim-only normalization, no uniqueness constraint. Max 100 characters.
- All identifiers preserved on archive/soft-delete — reuse prevented until administrative hard-delete.

### EAV Attributes (7 Data Types)

| Type | Storage | Validation |
|---|---|---|
| `text` | `value_text` (varchar 1000) | Max length |
| `integer` | `value_integer` (bigint) | Integer range |
| `decimal` | `value_decimal` (decimal 20,4) | Precision/scale |
| `boolean` | `value_boolean` (tinyint 1) | 0 or 1 |
| `date` | `value_date` (date) | ISO 8601 |
| `select` | `value_option_id` (FK → attribute_options) | Single existing option |
| `multiselect` | Attribute value row + pivot table | One or more existing options |

- Split tables: `product_attribute_values` and `variant_attribute_values` with real foreign keys (no polymorphic morphs)
- Scope enforcement: `product`, `variant`, or `both` (independent values, no inheritance)
- `required` flag blocks publication when value is missing
- `filterable` flag enables attribute-based product filtering
- `searchable` flag (provisioned for future search integration)
- Validation rules: min/max for numeric, regex for text, stored as JSON on definitions
- In-place edits on active products with audit trail

### Media Management

- **Supported formats:** JPEG (`image/jpeg`), PNG (`image/png`), WEBP (`image/webp`)
- **Rejected:** SVG (XSS risk), GIF, AVIF, PDF, video
- **Validation:** MIME type via finfo, image header bytes, declared MIME, file extension (all must agree); max 10 MB; max 12,000 × 12,000 pixels; max 40 megapixels
- **Integrity:** SHA-256 checksum computed server-side, stored in `product_media.checksum_sha256`
- **Storage:** Private `catalog_media` disk (default: `storage/app/catalog-media`). Paths: `{company_uuid}/products/{product_uuid}/{media_uuid}.{ext}`
- **Primary image:** Tracked via FKs on owning entity (`products.primary_media_id`, `product_variants.primary_media_id`). No `is_primary` boolean.
- **Delivery:** Authenticated inline streaming with `Content-Type`, `Content-Length`, SHA-256 ETag, `nosniff`, and private cache control
- **Limits:** 50 total images per Product (including Variant images); 10 images per Variant
- **Cleanup:** `catalog:media-cleanup` command with dry-run default

### Lifecycle (Draft → Active → Archived)

**Product transitions:**
```
draft --Activate (+ readiness)--> active
active --Return to draft--------> draft
draft --Archive-----------------> archived
active --Archive----------------> archived
archived --Restore--------------> draft
```

**Publication readiness (10 gates):**
| # | Gate | Level |
|---|---|---|
| 1 | Name non-empty | Hard gate |
| 2 | Slug present, non-empty, unique | Hard gate |
| 3 | At least one Variant exists | Hard gate |
| 4 | Default Variant assigned | Hard gate |
| 5 | Default Variant not archived | Hard gate |
| 6 | Primary Category assigned | Hard gate |
| 7 | Required product-level attributes present | Hard gate |
| 8 | Required variant-level attributes on default Variant | Hard gate |
| 9 | SKU on default Variant | Soft gate (warning only) |
| 10 | Primary media assigned | Soft gate (warning only) |

- Hard gates block activation. Soft gates produce warnings but do not block.
- `published_at` timestamp is set on first activation and preserved across archive/restore cycles.
- Direct `archived → active` is forbidden. Product must go through draft and re-pass readiness.

### Advanced Search with Filters

**Searchable fields:** Product name, slug, SKU, GTIN, MPN, brand, manufacturer

**Filters:**
- Status (draft/active/archived)
- Category UUIDs (primary or any, with optional descendant inclusion)
- Brand (exact match)
- Manufacturer (exact match)
- Readiness (ready/not_ready/any)
- Missing data points (primary_category, primary_media, description, attributes, variants)
- Attribute filters (dynamic, for definitions where `filterable = true`)

**Sorting:** name, brand, created_at, updated_at, variant_count, relevance (with direction)

**Pagination:** 25 default, 50 and 100 allowed (configurable)

### REST API (53 Endpoints)

Full CRUD for Categories, Products, Variants, Attributes, Options, Attribute Values, and Media. Lifecycle endpoints for activation, return-to-draft, archive, and restore.

- OpenAPI 3.1 specification
- Bearer token authentication via Laravel Sanctum
- 4 token abilities: `catalog.read`, `catalog.write`, `catalog.lifecycle`, `catalog.media`
- Company-scoped tokens (company resolved from token, not request body)
- Dual authorization: token ability + Company membership permission
- Rate limits: read (120/min), write (60/min), media (20/min), lifecycle (30/min)
- Structured responses: `{data, meta}` for resources, `{data, links, meta}` for collections
- ISO 8601 timestamps with microseconds, UUID-only identifiers, decimal-as-string
- 404 concealment for cross-tenant resources
- X-Request-ID on all responses
- 12 stable error codes (unauthenticated, forbidden, validation_failed, tenant_mismatch, etc.)

### Audit Trail

- 38 Actions producing 32 unique audit events
- Immutable append-only records (no update/delete routes, model-level prevention)
- Audit UI at `/catalog/audit` with filters (event type, actor, resource, date range, keyword)
- Detail view with safe metadata display
- Actor identified by email (never stores credentials, tokens, or passwords)
- All properties sanitized via `SensitiveDataSanitizer`
- Transaction-safe: failed mutations leave no audit records

### Integrity Diagnostics

- `catalog:integrity-check` — 8 checks across all catalog resources
- `catalog:summary` — Statistics: categories, products by status, variants, missing data, stale drafts
- `catalog:media-cleanup` — Orphan file detection and safe deletion (dry-run default)
- All commands support `--company=<uuid>` or `--all-companies` and `--format=table|json`
- Scheduler runs integrity and summary daily, media cleanup dry-run weekly

---

## 4. Architecture

### Action Pattern

All catalog mutations follow the **Action pattern**: each business operation is a dedicated Action class that validates input, re-authorizes the actor, applies business rules, persists changes in a transaction where required, and emits exactly one audit event. Controllers are thin HTTP adapters with no business logic.

### Multi-Tenant Isolation

Every catalog query includes `WHERE company_id = ?`. Tenant context is established by:
- **Web:** `SessionCurrentCompany` (selected company from session)
- **API:** `TokenCurrentCompany` (company_id stored on the token)

Cross-tenant resource access returns 404 (never 403). Denormalized `company_id` columns on child tables enable direct tenant-scoped queries with database-enforced composite foreign keys.

### Web/API Parity

Web and API controllers share identical Action classes. Same business logic, same validation, same audit events, same authorization. Only the transport layer differs: Blade views vs JSON responses.

### MySQL 8 Only

R1 requires MySQL 8.0. Features used:
- Composite foreign keys with multiple columns (same-company enforcement)
- CHECK constraints (status values, depth range, typed value exclusivity, GTIN format)
- UNIQUE indexes on nullable columns (GTIN NULL handling)
- Triggers (self-parent prevention for categories)
- Composite unique keys for FK targeting
- Transaction isolation for concurrent Product/Variant/Category mutations

---

## 5. API

### Base URI

```
https://<domain>/api/v1/catalog
```

### Authentication

```http
Authorization: Bearer <token>
Accept: application/json
```

Token requirements:
- Valid, non-expired, non-revoked Sanctum token
- Token assigned to a specific Company (`company_id` non-null)
- Token user is active and has active membership in the token's company
- Token company is active

### Abilities

| Ability | Value | Purpose |
|---|---|---|
| Catalog Read | `catalog.read` | All GET operations |
| Catalog Write | `catalog.write` | Create/update resources, manage attributes |
| Catalog Lifecycle | `catalog.lifecycle` | Activate, return-to-draft, archive, restore |
| Catalog Media | `catalog.media` | Upload, update, delete, set-primary, reorder media |

### Endpoint Groups

| Group | Count | Resources |
|---|---|---|
| Categories | 8 | List, create, show, update, move, reorder, archive, restore |
| Products | 4 | List/search, create, show, update |
| Product Variants | 5 | List, create, show, update, set-default |
| Attribute Definitions | 6 | List, create, show, update, archive, restore |
| Attribute Options | 6 | List, create, update, archive, restore, reorder |
| Product Attribute Values | 2 | Read, full sync |
| Variant Attribute Values | 2 | Read, full sync |
| Product Media | 6 | List, upload, update metadata, set-primary, reorder, delete |
| Variant Media | 6 | List, upload, update metadata, set-primary, reorder, delete |
| Media Content | 1 | Authenticated inline delivery |
| Lifecycle | 7 | Readiness check, activate, return-to-draft, archive (product + variant), restore (product + variant) |

**53 total routes** with `api.v1.catalog.` naming prefix.

### Response Format

**Single resource:**
```json
{
  "data": { "uuid": "...", "name": "...", "status": "draft" },
  "meta": { "request_id": "uuid" }
}
```

**Collection:**
```json
{
  "data": [...],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "request_id": "uuid", "current_page": 1, "per_page": 25, "total": 0, "last_page": 1 }
}
```

**Error:**
```json
{
  "data": null,
  "meta": { "request_id": "uuid" },
  "error": { "code": "validation_failed", "message": "...", "details": {} }
}
```

### Pagination

Default 25 per page, max 100. Accepts `per_page=25|50|100` and `page=N`.

### Serialization Rules

- All identifiers: UUIDs (never numeric IDs)
- Timestamps: ISO 8601 UTC with microseconds (`2026-07-16T12:00:00.000000Z`)
- Decimals: string format (`"99.9500"`)
- Dates: `Y-m-d` format
- Enums: string-backed values
- Never exposed: `company_id`, `created_by`, `updated_by`, `deleted_at`, normalized columns, storage paths, checksums

### Stable Error Codes

| Code | HTTP | Meaning |
|---|---|---|
| `unauthenticated` | 401 | Invalid/expired/revoked token |
| `token_ability_missing` | 403 | Token lacks required ability |
| `forbidden` | 403 | Permission denied |
| `resource_not_found` | 404 | Resource not found or wrong tenant |
| `validation_failed` | 422 | Validation failure |
| `tenant_mismatch` | 404 | Resource belongs to another Company |
| `identifier_conflict` | 409 | Duplicate slug/SKU/GTIN/code |
| `invalid_state_transition` | 409 | Forbidden lifecycle transition |
| `activation_blocked` | 422 | Readiness check failed |
| `media_validation_failed` | 422 | Invalid media file |
| `rate_limited` | 429 | Rate limit exceeded |
| `internal_error` | 500 | Unexpected server error |

---

## 6. Database

### Migration Inventory

24 total migrations: 12 R0 foundation + 12 R1 catalog.

**R1 Catalog migrations (all dated 2026-07-14):**

| # | File | Table |
|---|---|---|
| 1 | `000001_create_categories_table` | `categories` |
| 2 | `000002_create_products_table` | `products` |
| 3 | `000003_create_product_variants_table` | `product_variants` |
| 4 | `000004_create_category_product_table` | `category_product` |
| 5 | `000005_create_attribute_definitions_table` | `attribute_definitions` |
| 6 | `000006_create_attribute_options_table` | `attribute_options` |
| 7 | `000007_create_product_attribute_values_table` | `product_attribute_values` |
| 8 | `000008_create_variant_attribute_values_table` | `variant_attribute_values` |
| 9 | `000009_create_product_attribute_value_options_table` | `product_attribute_value_options` |
| 10 | `000010_create_variant_attribute_value_options_table` | `variant_attribute_value_options` |
| 11 | `000011_create_product_media_table` | `product_media` |
| 12 | `000012_add_catalog_deferred_foreign_keys` | Deferred pointer FKs on products + product_variants |

### Key Database Properties

- **Engine:** InnoDB (all tables)
- **Charset:** `utf8mb4` / `utf8mb4_unicode_ci`
- **Foreign keys:** 32 total (11 simple + 21 composite) with appropriate CASCADE/RESTRICT/SET NULL actions
- **Unique constraints:** 11 across all catalog tables
- **CHECK constraints:** 19 enforcing status values, depth range, sort_order, typed value exclusivity, GTIN format, media checksum format
- **Indexes:** 26 composite indexes for tenant-scoped query performance
- **Triggers:** 2 (INSERT + UPDATE) preventing Category self-parenting

### Tenant-Safe Foreign Keys

Composite foreign keys include `company_id` on both sides, preventing cross-tenant data insertion even at the database level. All FK validation is enforced by MySQL without relying solely on application logic.

### Soft Delete and Identifier Reservation

Archived and soft-deleted records preserve their identifiers (slugs, SKUs, GTINs, codes). The normalized columns remain occupied, preventing accidental reuse. Only administrative hard-delete (CLI only) frees identifiers.

---

## 7. Testing

### Test Suite Summary

- **Catalog-specific tests:** 30 test files covering schema, models, actions, authorization, API, audit, operations, search, media, lifecycle, and attributes
- **Full test suite:** All tests run on MySQL 8 (`nordipass_testing` database); SQLite is not supported for catalog tests

All tests pass. PHPStan reports 0 errors. Laravel Pint reports no style violations.

### Test Categories

| Category | Test Files | Coverage |
|---|---|---|
| Schema | 7 | Table/column inventory, tenant constraints, pointer integrity, unique constraints, attribute integrity, media integrity, foreign key verification via `information_schema` |
| API | 12 | Authentication, abilities, permissions, tenant isolation, serialization, pagination, search, search parity (Web vs API), includes, error responses, rate limits, request IDs |
| Audit | 4 | UI access/filters/pagination, Action coverage (24 tests), query builder (9 tests), general audit |
| Operations | 3 | Integrity scanner (18 tests), console commands (18 tests), DTO unit tests (8 tests) |
| Foundation | 2 | Eloquent models, enums, identifier normalizer |
| Products | 2 | Actions, demo seeder |
| Lifecycle | 1 | Product and Variant lifecycle transitions, readiness, invariants |
| Search | 1 | Full-text search, filters, sorting, pagination |
| Media | Feature + Unit | Upload validation, metadata, primary, reorder, delete, checksums, path guard |
| Attributes | Feature + Unit | Definitions, options, values, scope enforcement, typed validation |

### CI Pipeline

GitHub Actions runs on every PR and push to `master`. 23 steps across backend and frontend jobs, all blocking (no `allow_failure`). MySQL 8.0 service container. PHPStan and Pint failures block the build.

---

## 8. Known Limitations

### Pre-existing Skipped Tests (Not Catalog-Related)

2 tests are marked as skipped in the default test suite. These are R0 platform tests unrelated to Catalog:
- `onOneServer` job behavior test (no shared lock configured in test environment)
- Rate limiter state isolation test (environment-specific behavior)

These do not affect R1 functionality and are deferred to a future platform maintenance stage.

### Not Implemented in R1 (by Design)

- **Optimistic concurrency:** No `If-Match` / ETag-based conflict detection on API mutations
- **Idempotency keys:** No `Idempotency-Key` header support at HTTP level (domain-level idempotency applies for no-op operations)
- **`onOneServer`:** Not enabled (no shared lock infrastructure configured)
- **API content negotiation:** Only `Accept: application/json` supported; no XML, HAL, or JSON:API formats

---

## 9. Deferred to R2+

The following features are intentionally excluded from R1. They are documented in `R1_CATALOG_SCOPE.md` §3 and `CATALOG_DOMAIN.md`:

### Commerce & Pricing
- List prices, cost prices, sale prices, discount rules
- Currencies and multi-currency support
- VAT rates, tax classes, tax zones
- Inventory levels, stock keeping, warehouses
- Suppliers, purchase orders
- Orders, carts, checkout

### Documents & Digital
- Document management module
- PDF generation
- QR code generation and management
- Digital Product Passport (DPP)
- Public product pages / storefront
- Custom domains for storefront

### Integrations
- Fortnox integration (accounting, invoicing)
- Excel import and export
- CSV/JSON bulk import
- Bulk edit operations
- Webhooks and event subscriptions
- External search engines (Meilisearch, Elasticsearch)

### AI & Intelligence
- AI text generation (product descriptions)
- AI translation of product content
- RAG-based product search/chat
- Analytics and reporting
- Recommendation engine

### Advanced Features
- Product relationships (accessories, cross-sells, up-sells)
- Product bundles and kits
- Version history / revision tracking
- Approval workflow (multi-step review)
- Product duplication/clone
- Mass status changes (bulk activate/archive)
- Scheduled publication (future-dated activation)

### Localization
- Multi-language content (translations)
- Locale-aware API responses
- Per-language slugs and URLs
- Unit conversion engine

### Developer Experience
- API SDK
- GraphQL endpoint
- Mobile application

---

## 10. Breaking Changes

**None.** This is the initial release of the Catalog module. No existing R0 functionality is modified, deprecated, or removed. R1 extends R0 through documented extension points:

- `CompanyPermission` enum extended with 8 new values (R0 permissions unchanged)
- `CompanyPermissionMatrix` extended with catalog role mappings (R0 mappings unchanged)
- `AuditEvent` enum extended with 32 catalog event values (R0 events unchanged)
- `ApiTokenAbility` enum extended with 4 catalog abilities (R0 abilities unchanged)
- New tables added; no R0 tables modified

---

## 11. Requirements

### Server

| Requirement | Version |
|---|---|
| **PHP** | 8.4+ |
| **Laravel** | 11.x |
| **MySQL** | 8.0+ (exclusive — no PostgreSQL, SQLite, or MariaDB support) |
| **Composer** | 2.x |
| **PHP Extensions** | mbstring, pdo, pdo_mysql, fileinfo, gd or imagick (image processing) |

### Frontend

| Requirement | Version |
|---|---|
| **Node.js** | 20+ |
| **npm** | 10+ |
| **Build** | Vite (Laravel default) |

### Infrastructure

- **Web server:** Nginx or Apache with PHP-FPM
- **Storage:** Local filesystem or S3-compatible object storage (Laravel filesystem abstraction)
- **Cache:** Redis recommended for production (file/database cache acceptable for development)
- **Queue:** Redis or database queue driver

### Environment Variables

| Variable | Purpose | Default |
|---|---|---|
| `CATALOG_MEDIA_ROOT` | Root path for catalog media storage | `storage/app/catalog-media` |
| `CATALOG_MAX_CATEGORY_DEPTH` | Maximum category tree depth | `5` |
| `CATALOG_MAX_VARIANTS_PER_PRODUCT` | Maximum variants per product | `100` |
| `CATALOG_MAX_CATEGORIES_PER_PRODUCT` | Maximum categories per product | `20` |
| `CATALOG_MAX_MEDIA_PER_PRODUCT` | Maximum images per product (total) | `50` |
| `CATALOG_MAX_MEDIA_PER_VARIANT` | Maximum images per variant | `10` |
| `CATALOG_MAX_ATTRIBUTES_PER_COMPANY` | Maximum attribute definitions | `500` |
| `CATALOG_MAX_OPTIONS_PER_DEFINITION` | Maximum options per definition | `200` |

---

## References

- **Quality review:** [R1_FINAL_QUALITY_REVIEW.md](R1_FINAL_QUALITY_REVIEW.md)
- **Release checklist:** [R1_RELEASE_CHECKLIST.md](R1_RELEASE_CHECKLIST.md)
- **Scope:** [R1_CATALOG_SCOPE.md](R1_CATALOG_SCOPE.md)
- **Domain:** [CATALOG_DOMAIN.md](CATALOG_DOMAIN.md)
- **Decisions:** [CATALOG_DECISIONS.md](CATALOG_DECISIONS.md)
- **Schema:** [CATALOG_SCHEMA.md](CATALOG_SCHEMA.md)
- **API:** [CATALOG_API.md](CATALOG_API.md)
- **Audit:** [CATALOG_AUDIT.md](CATALOG_AUDIT.md)
- **Operations:** [CATALOG_OPERATIONS.md](CATALOG_OPERATIONS.md)
