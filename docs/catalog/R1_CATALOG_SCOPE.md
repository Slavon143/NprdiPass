# NordiPass R1 — Core Catalog Scope

**Stage:** R1.1
**Date:** 2026-07-14
**Status:** Scope defined; implementation begins at R1.2

---

## 1. R1 Scope Statement

**R1 delivers the Core Catalog module for NordiPass.** A Company can define Products, Variants, Categories, Attributes, and Media. Products follow a draft→active→archived lifecycle with publication readiness gates. The catalog is fully tenant-isolated, permission-controlled, and audit-logged.

---

## 2. Included in R1

### R1.2 — Database Schema
- All catalog migration files (products, product_variants, categories, category_product, attribute_definitions, attribute_options, product_attribute_values, variant_attribute_values, attribute_value_options, product_media)
- Unique constraints, foreign keys, and partial indices as defined in CATALOG_DOMAIN.md
- `HasUuid` trait on all catalog models
- `SoftDeletes` trait on all catalog models

### R1.3 — Catalog Foundation
- Extend `CompanyPermission` enum with 8 catalog permissions
- Extend `CompanyPermissionMatrix` for new catalog permissions
- Create 5 Policies: `ProductPolicy`, `ProductVariantPolicy`, `CategoryPolicy`, `AttributeDefinitionPolicy`, `ProductMediaPolicy`
- Extend `AuditEvent` enum with 17 catalog audit events
- Register named Gates for all catalog permissions
- Extend `SensitiveDataSanitizer` if needed for catalog-specific redaction

### R1.4 — Categories
- Category model, migration, and seeder
- CRUD Actions: Create, Update, Move (change parent), Archive, Restore
- Category tree with adjacency list, depth validation, cycle detection
- Category UI pages under `/settings/catalog/categories`
- Category form requests with tenant-scoped validation
- Category audit logging

### R1.5 — Products
- Product model, migration, and seeder
- CRUD Actions: Create (atomic with default Variant), Update, Archive, Activate, Restore
- Publication readiness validation
- Category assignment (many-to-many with primary_category_id FK on Product)
- Product UI pages under `/catalog/products`
- Product form requests with slug uniqueness validation
- Product audit logging

### R1.6 — Variants
- ProductVariant model, migration, and seeder
- CRUD Actions: Create, Update, Archive, Restore, SetDefault
- SKU normalization and uniqueness validation
- GTIN validation (check digit, length) with `UNIQUE(company_id, gtin)` utilizing MySQL's nullable behavior
- Variant UI within Product context
- Variant audit logging

### R1.7 — Attributes
- AttributeDefinition + AttributeOption models and migrations
- product_attribute_values + variant_attribute_values tables (typed nullable columns with real FKs)
- attribute_value_options pivot table for multiselect
- CRUD Actions for definitions, options, and values
- Attribute assignment to Products and Variants
- Scope enforcement (product/variant/both)
- UI for managing attribute definitions and assigning values
- Attribute audit logging

### R1.8 — Media
- ProductMedia model and migration
- Image upload (JPEG, PNG, WEBP) with SHA-256 integrity
- Primary image management via `products.primary_media_id` and `product_variants.primary_media_id` FK columns
- Media metadata (alt text, caption, dimensions)
- Soft-delete for media records
- `nordipass:prune-orphan-media` command
- Media audit logging

### R1.9 — Lifecycle
- Product activation/deactivation with publication readiness
- Product archiving with cascaded Variant archiving
- Default Variant invariants enforced at runtime (nullable `default_variant_id` FK, set in transaction)
- Variant lifecycle transitions within Product context
- Row locking for concurrent Product mutations

### R1.10 — Search & Listing
- Product listing with filters (status, category, brand, attribute)
- MySQL-based search (name, SKU, GTIN, MPN)
- Sorting (name, created_at, SKU)
- Pagination (25 default, 100 max)
- Category browsing with product counts

### R1.11 — API
- API routes under `/api/v1/products`, `/api/v1/categories`, `/api/v1/attributes`
- API middleware chain for catalog endpoints (token + membership + ability)
- `ApiTokenAbility` enum extended with 6 catalog abilities
- Structured API responses following R0 conventions
- Rate limits for catalog API endpoints
- API pagination and filtering

---

## 3. Excluded from R1 (Deferred)

All items listed here are intentionally absent from R1. They are neither designed nor implemented.

### Commerce & Pricing
- List prices, cost prices, sale prices, discounted prices
- Currencies and multi-currency support
- VAT rates, tax classes, tax zones
- Discount rules and promotions
- Inventory levels and stock keeping
- Warehouses and multi-location inventory
- Suppliers and purchase orders
- Orders, carts, and checkout

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
- Bulk import (CSV, JSON)
- Bulk edit operations
- Webhooks and event subscriptions

### AI & Intelligence
- AI text generation (product descriptions)
- AI translation of product content
- RAG-based product search/chat
- Analytics and reporting
- Recommendation engine

### Advanced Product Features
- Product relationships (accessories, cross-sells, up-sells)
- Product bundles and kits
- Version history / revision tracking
- Approval workflow (multi-step review)
- Custom fields without AttributeDefinition
- Product duplication/clone
- Mass status changes (bulk activate/archive)
- Scheduled publication (future-dated activation)

### Localization
- Multi-language content (translations)
- Locale-aware API responses
- Per-language slugs and URLs
- Unit conversion engine
- Country-specific validation rules

### Search Infrastructure
- Elasticsearch / Meilisearch integration
- Faceted search
- Autocomplete / typeahead search
- Synonym management
- Search analytics

---

## 4. Dependencies on R0

R1 depends on the following R0 components (no changes to R0 code in R1.1):

| R0 Component | R1 Usage |
|---|---|
| `Company` model | Tenant ownership of all catalog entities |
| `CompanyMembership` model | Role-based catalog permissions |
| `CompanyStatus` enum | Active/suspended/archived tenant checks |
| `UserStatus` enum | Active user check in authorizer |
| `SessionCurrentCompany` | Web context tenant resolution |
| `TokenCurrentCompany` | API context tenant resolution |
| `CompanyResolver` | Auto-selection of company context |
| `CompanyPermission` enum | Extended with catalog permissions |
| `CompanyPermissionMatrix` | Extended with catalog role mappings |
| `CompanyAuthorizer` | Authorization for catalog operations |
| `CompanyPermissionGate` | Named Gates for catalog operations |
| `CompanyRole` enum | Owner/Admin/Editor/Viewer roles used in catalog permission matrix |
| `AuditLog` model | Immutable audit log for catalog events |
| `AuditLogger` | `logTenant()` for catalog events |
| `SensitiveDataSanitizer` | Sanitizes catalog audit properties |
| `HasUuid` trait | UUID generation for all catalog models |
| Tenant middleware chain | `auth → verified → company.resolve → company.selected → member → active` |
| API middleware chain | `EnsureApiTokenIsValid → ResolveApiCompany → EnsureApiCompanyMembership → EnsureApiCompanyIsActive → EnsureApiTokenAbility` |
| `ApiTokenAbility` enum | Extended with 6 catalog abilities |
| `PersonalAccessToken` model | Company-scoped tokens for catalog API |
| Rate limit infrastructure | New catalog rate limiters |
| Config pattern | `CATALOG_*` env vars in `config/catalog.php` |
| Pagination conventions | 25 default, 100 max |

---

## 5. Future Extension Points

The R1 model intentionally leaves space for these future modules:

| Extension Point | R1 Design Decision | Future Compatibility |
|---|---|---|
| **Prices** | Price NOT on Product or Variant | Add `product_prices` or `variant_prices` table with `variant_id` FK |
| **Inventory** | Stock NOT on Variant | Add `inventory` table with `variant_id` + `warehouse_id` FK |
| **Translations** | Single-language varchar/text columns | Add polymorphic `translations` table: `(entity_type, entity_id, field, locale, value)` |
| **Documents** | Documents separate from media | Add `documents` table with `documentable_type` + `documentable_id` polymorphic FK |
| **DPP** | Product + Variant UUIDs available | DPP module references `product_uuid` or `variant_uuid` |
| **QR Codes** | SKU/GTIN on Variant | QR payload uses Variant UUID, SKU, or GTIN |
| **Fortnox** | SKU on Variant, GTIN validated | Fortnox article mapping uses `variant.sku_normalized` or `variant.gtin` |
| **Public Pages** | Slug unique per Company | Public URLs: `/{slug}` scoped by Company domain |
| **Elasticsearch** | Searchable field flags on AttributeDefinition | Index with field-level extraction, no schema change needed |
| **Unit Conversion** | Free-text unit on AttributeDefinition | Future `units` table or conversion ratios can reference the unit string |

---

## 6. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| R1 scope creep into pricing/inventory | MEDIUM | Explicit deferred list in this document |
| Attribute EAV table performance at scale | LOW | Typed nullable columns are performant to ~1M values; indexes planned |
| Category tree performance with adjacency list | LOW | Depth limit of 5, expected <500 categories per Company |
| Soft-delete + archive status confusion | LOW | Documented two-layer approach; archive = user action, soft-delete = admin |
| SKU uniqueness blocking during concurrent creates | LOW | Database UNIQUE constraint handles race; Action catches and reports |
| API token abilities too coarse | LOW | Future granular abilities can split `catalog.write` without schema change |

---

## 7. Assumptions

1. **R1 is single-Company per user session.** Company switching exists in R0; catalog context follows the selected company.
2. **No public-facing catalog in R1.** All catalog pages require authentication + company membership.
3. **No multi-language content in R1.** All text stored in a single language per Company.
4. **MySQL is the only supported database.** GTIN uniqueness uses MySQL's native NULL-handling in UNIQUE indexes. No PostgreSQL-style partial indexes are needed.
5. **Media files are stored on configured filesystem disk.** Local disk default; S3 ready via Laravel filesystem abstraction.
6. **Catalog size per Company:** <50k products, <500 categories, <500 attribute definitions (design assumption for R1; scalable to larger volumes in future with index optimization).
7. **No batch operations in R1.** Products are created/edited one at a time. Bulk import is explicitly deferred.

---

## 8. References

- **Domain definition:** [CATALOG_DOMAIN.md](CATALOG_DOMAIN.md)
- **Architecture decisions:** [CATALOG_DECISIONS.md](CATALOG_DECISIONS.md)
- **R0 architecture:** [README.md](../../README.md), [API.md](../../docs/API.md)
- **R0 release notes:** [R0_RELEASE_NOTES.md](../../docs/release/R0_RELEASE_NOTES.md)
