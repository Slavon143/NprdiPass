# NordiPass R1.2 — Catalog Database Schema

**Stage:** R1.2
**Date:** 2026-07-14
**Status:** Implemented
**Database:** MySQL 8 (production, local, CI, and all database-backed tests)

---

## 1. Database Engine

| Environment | Engine | Notes |
|---|---|---|
| Production | MySQL 8 | Redis for cache/session/queue |
| Local | MySQL 8 | Database cache/session/queue |
| CI | MySQL 8.0 service | GitHub Actions |
| Integration tests | MySQL 8 | `nordipass_testing` database |
| Unit tests | No database required | Pure PHP unit tests |

---

## 2. Migration Order

| # | Migration | Tables/Constraints | Reason |
|---|---|---|---|
| 1 | `2026_07_14_000001_create_categories_table` | `categories` | No catalog dependencies; composite self-referencing FK for parent |
| 2 | `2026_07_14_000002_create_products_table` | `products` | Pointer columns nullable (no FKs yet) |
| 3 | `2026_07_14_000003_create_product_variants_table` | `product_variants` | Composite FK to products(company_id, id) |
| 4 | `2026_07_14_000004_create_category_product_table` | `category_product` | Pivot, composite FKs to products + categories |
| 5 | `2026_07_14_000005_create_attribute_definitions_table` | `attribute_definitions` | Independent aggregate |
| 6 | `2026_07_14_000006_create_attribute_options_table` | `attribute_options` | Composite FK to attribute_definitions |
| 7 | `2026_07_14_000007_create_product_attribute_values_table` | `product_attribute_values` | Typed columns + composite FKs |
| 8 | `2026_07_14_000008_create_variant_attribute_values_table` | `variant_attribute_values` | Typed columns + composite FKs |
| 9 | `2026_07_14_000009_create_product_attribute_value_options_table` | `product_attribute_value_options` | Multiselect pivot |
| 10 | `2026_07_14_000010_create_variant_attribute_value_options_table` | `variant_attribute_value_options` | Multiselect pivot |
| 11 | `2026_07_14_000011_create_product_media_table` | `product_media` | Composite FK to variants(company_id, product_id, id) |
| 12 | `2026_07_14_000012_add_catalog_deferred_foreign_keys` | Deferred FKs on products + product_variants | Added after all referenced tables exist |

**Design rationale:** Product creates with `default_variant_id=NULL`. Variant is created in the same transaction. Product updates `default_variant_id` to the new Variant ID before commit. The nullable three-column FK from migration 12 validates both same-company and same-product integrity. No `SET FOREIGN_KEY_CHECKS=0` used.

---

## 3. Tables Created

| Table | Purpose | Tenant Key | Soft Delete | UUID |
|---|---|---|---|---|
| `categories` | Product classification hierarchy | `company_id` (NOT NULL, FK→companies) | `deleted_at` | `uuid` (unique) |
| `products` | Aggregate root for catalog entities | `company_id` (NOT NULL, FK→companies) | `deleted_at` | `uuid` (unique) |
| `product_variants` | Sellable configurations with identifiers | `company_id` (NOT NULL, FK→companies) | `deleted_at` | `uuid` (unique) |
| `category_product` | Many-to-many Product–Category assignment | `company_id` (NOT NULL, FK→companies) | No | No (uses `id`) |
| `attribute_definitions` | Schema for product characteristics | `company_id` (NOT NULL, FK→companies) | No | `uuid` (unique) |
| `attribute_options` | Predefined choices for select/multiselect | `company_id` (NOT NULL, FK→companies) | No | No (uses `id`) |
| `product_attribute_values` | Typed values for Product attributes | `company_id` (NOT NULL, FK→companies) | No | No (uses `id`) |
| `variant_attribute_values` | Typed values for Variant attributes | `company_id` (NOT NULL, FK→companies) | No | No (uses `id`) |
| `product_attribute_value_options` | Multiselect assignments (Product) | `company_id` (NOT NULL, FK→companies) | No | No (uses `id`) |
| `variant_attribute_value_options` | Multiselect assignments (Variant) | `company_id` (NOT NULL, FK→companies) | No | No (uses `id`) |
| `product_media` | Image files for Products and Variants | `company_id` (NOT NULL, FK→companies) | `deleted_at` | `uuid` (unique) |

### Column Inventory

| Table | Columns |
|---|---|
| `categories` | `id`, `uuid`, `company_id`, `parent_id`, `depth`, `name`, `slug`, `slug_normalized`, `description`, `sort_order`, `status`, `created_by`, `updated_by`, timestamps, `deleted_at` |
| `products` | `id`, `uuid`, `company_id`, `primary_category_id`, `default_variant_id`, `primary_media_id`, `name`, `slug`, `slug_normalized`, `short_description`, `description`, `brand`, `manufacturer`, `status`, `published_at`, `created_by`, `updated_by`, timestamps, `deleted_at` |
| `product_variants` | `id`, `uuid`, `company_id`, `product_id`, `primary_media_id`, `name`, `sku`, `sku_normalized`, `gtin`, `mpn`, `is_default`, `status`, `sort_order`, `created_by`, `updated_by`, timestamps, `deleted_at` |
| `category_product` | `id`, `company_id`, `product_id`, `category_id`, `created_at` |
| `attribute_definitions` | `id`, `uuid`, `company_id`, `name`, `code`, `description`, `type`, `scope`, `unit`, `required`, `filterable`, `searchable`, `validation_rules`, `sort_order`, `status`, `created_by`, `updated_by`, timestamps |
| `attribute_options` | `id`, `company_id`, `attribute_definition_id`, `label`, `code`, `sort_order`, `status`, timestamps |
| `product_attribute_values` | `id`, `company_id`, `product_id`, `attribute_definition_id`, six typed value columns, timestamps |
| `variant_attribute_values` | `id`, `company_id`, `product_variant_id`, `attribute_definition_id`, six typed value columns, timestamps |
| `product_attribute_value_options` | `id`, `company_id`, `attribute_definition_id`, `product_attribute_value_id`, `attribute_option_id`, `created_at` |
| `variant_attribute_value_options` | `id`, `company_id`, `attribute_definition_id`, `variant_attribute_value_id`, `attribute_option_id`, `created_at` |
| `product_media` | `id`, `uuid`, `company_id`, `product_id`, `product_variant_id`, filename/path/MIME fields, `size_bytes`, `width`, `height`, `checksum_sha256`, `alt_text`, `caption`, `sort_order`, `uploaded_by`, timestamps, `deleted_at` |

---

## 4. Foreign Keys

### Simple Foreign Keys

| Constraint | Source | Target | On Delete |
|---|---|---|---|
| `categories_company_id_foreign` | `categories.company_id` | `companies.id` | CASCADE |
| `products_company_id_foreign` | `products.company_id` | `companies.id` | CASCADE |
| `product_variants_company_id_foreign` | `product_variants.company_id` | `companies.id` | CASCADE |
| `category_product_company_id_foreign` | `category_product.company_id` | `companies.id` | CASCADE |
| `attribute_definitions_company_id_foreign` | `attribute_definitions.company_id` | `companies.id` | CASCADE |
| `attribute_options_company_id_foreign` | `attribute_options.company_id` | `companies.id` | CASCADE |
| `product_attribute_values_company_id_foreign` | `product_attribute_values.company_id` | `companies.id` | CASCADE |
| `variant_attribute_values_company_id_foreign` | `variant_attribute_values.company_id` | `companies.id` | CASCADE |
| `product_attribute_value_options_company_id_foreign` | `product_attribute_value_options.company_id` | `companies.id` | CASCADE |
| `variant_attribute_value_options_company_id_foreign` | `variant_attribute_value_options.company_id` | `companies.id` | CASCADE |
| `product_media_company_id_foreign` | `product_media.company_id` | `companies.id` | CASCADE |
| `categories_created_by_foreign` | `categories.created_by` | `users.id` | SET NULL |
| `categories_updated_by_foreign` | `categories.updated_by` | `users.id` | SET NULL |
| `products_created_by_foreign` | `products.created_by` | `users.id` | SET NULL |
| `products_updated_by_foreign` | `products.updated_by` | `users.id` | SET NULL |
| `product_variants_created_by_foreign` | `product_variants.created_by` | `users.id` | SET NULL |
| `product_variants_updated_by_foreign` | `product_variants.updated_by` | `users.id` | SET NULL |
| `product_media_uploaded_by_foreign` | `product_media.uploaded_by` | `users.id` | SET NULL |

### Composite Foreign Keys (Same-Company Enforcement)

| Constraint | Source Columns | Target Table | Target Columns | On Delete |
|---|---|---|---|---|
| `categories_company_parent_foreign` | `(company_id, parent_id)` | `categories` | `(company_id, id)` | RESTRICT |
| `variants_company_product_foreign` | `(company_id, product_id)` | `products` | `(company_id, id)` | CASCADE |
| `category_product_company_product_foreign` | `(company_id, product_id)` | `products` | `(company_id, id)` | CASCADE |
| `category_product_company_category_foreign` | `(company_id, category_id)` | `categories` | `(company_id, id)` | CASCADE |
| `attr_options_company_definition_foreign` | `(company_id, attribute_definition_id)` | `attribute_definitions` | `(company_id, id)` | CASCADE |
| `product_attr_values_company_product_foreign` | `(company_id, product_id)` | `products` | `(company_id, id)` | CASCADE |
| `product_attr_values_company_def_foreign` | `(company_id, attribute_definition_id)` | `attribute_definitions` | `(company_id, id)` | CASCADE |
| `product_attr_values_company_def_option_foreign` | `(company_id, attribute_definition_id, value_option_id)` | `attribute_options` | `(company_id, attribute_definition_id, id)` | RESTRICT |
| `variant_attr_values_company_variant_foreign` | `(company_id, product_variant_id)` | `product_variants` | `(company_id, id)` | CASCADE |
| `variant_attr_values_company_def_foreign` | `(company_id, attribute_definition_id)` | `attribute_definitions` | `(company_id, id)` | CASCADE |
| `variant_attr_values_company_def_option_foreign` | `(company_id, attribute_definition_id, value_option_id)` | `attribute_options` | `(company_id, attribute_definition_id, id)` | RESTRICT |
| `product_attr_value_opts_value_foreign` | `(company_id, attribute_definition_id, product_attribute_value_id)` | `product_attribute_values` | `(company_id, attribute_definition_id, id)` | CASCADE |
| `product_attr_value_opts_option_foreign` | `(company_id, attribute_definition_id, attribute_option_id)` | `attribute_options` | `(company_id, attribute_definition_id, id)` | RESTRICT |
| `variant_attr_value_opts_value_foreign` | `(company_id, attribute_definition_id, variant_attribute_value_id)` | `variant_attribute_values` | `(company_id, attribute_definition_id, id)` | CASCADE |
| `variant_attr_value_opts_option_foreign` | `(company_id, attribute_definition_id, attribute_option_id)` | `attribute_options` | `(company_id, attribute_definition_id, id)` | RESTRICT |
| `media_company_product_foreign` | `(company_id, product_id)` | `products` | `(company_id, id)` | CASCADE |
| `media_company_product_variant_foreign` | `(company_id, product_id, product_variant_id)` | `product_variants` | `(company_id, product_id, id)` | RESTRICT |
| `products_primary_category_foreign` | `(company_id, primary_category_id)` | `categories` | `(company_id, id)` | RESTRICT |
| `products_default_variant_foreign` | `(company_id, id, default_variant_id)` | `product_variants` | `(company_id, product_id, id)` | RESTRICT |
| `products_primary_media_foreign` | `(company_id, id, primary_media_id)` | `product_media` | `(company_id, product_id, id)` | RESTRICT |
| `variants_primary_media_foreign` | `(company_id, product_id, id, primary_media_id)` | `product_media` | `(company_id, product_id, product_variant_id, id)` | RESTRICT |

---

## 5. Unique Constraints

| Constraint | Columns | Business Invariant |
|---|---|---|
| `categories_company_slug_unique` | `(company_id, slug_normalized)` | No duplicate Category slugs per Company |
| `products_company_slug_unique` | `(company_id, slug_normalized)` | No duplicate Product slugs per Company |
| `variants_company_sku_unique` | `(company_id, sku_normalized)` | No duplicate SKU per Company |
| `variants_company_gtin_unique` | `(company_id, gtin)` | No duplicate GTIN per Company (MySQL allows multiple NULLs) |
| `attr_defs_company_code_unique` | `(company_id, code)` | No duplicate Attribute codes per Company |
| `attr_options_company_definition_code_unique` | `(company_id, attribute_definition_id, code)` | No duplicate Option codes per Definition |
| `category_product_unique` | `(company_id, product_id, category_id)` | No duplicate Category assignments |
| `product_attr_values_entity_def_unique` | `(company_id, product_id, attribute_definition_id)` | One value per attribute per Product |
| `variant_attr_values_entity_def_unique` | `(company_id, product_variant_id, attribute_definition_id)` | One value per attribute per Variant |
| `product_attr_value_opts_unique` | `(company_id, product_attribute_value_id, attribute_option_id)` | No duplicate multiselect option |
| `variant_attr_value_opts_unique` | `(company_id, variant_attribute_value_id, attribute_option_id)` | No duplicate multiselect option |

---

## 6. Composite Unique Keys (for FK Referencing)

| Table | Unique Key | Purpose |
|---|---|---|
| `categories` | `(company_id, id)` | Referenced by composite FKs (parent, product pivot, primary_category) |
| `products` | `(company_id, id)` | Referenced by composite FKs (variants, pivot, attribute values, media) |
| `product_variants` | `(company_id, id)` | Referenced by composite FKs (attribute values, default_variant) |
| `product_variants` | `(company_id, product_id, id)` | Referenced by media composite FK |
| `attribute_definitions` | `(company_id, id)` | Referenced by composite FKs (options, attribute values) |
| `attribute_options` | `(company_id, attribute_definition_id, id)` | Referenced by attribute value option FKs |
| `product_attribute_values` | `(company_id, attribute_definition_id, id)` | Ensures a multiselect pivot uses the value's Definition |
| `variant_attribute_values` | `(company_id, attribute_definition_id, id)` | Ensures a multiselect pivot uses the value's Definition |
| `product_media` | `(company_id, product_id, id)` | Referenced by Product primary-media pointer |
| `product_media` | `(company_id, product_id, product_variant_id, id)` | Referenced by Variant primary-media pointer |

---

## 7. CHECK Constraints

| Constraint | Table | Rule |
|---|---|---|
| `categories_depth_check` | `categories` | `depth >= 0 AND depth <= 5` |
| `categories_status_check` | `categories` | `status IN ('active', 'archived')` |
| `categories_sort_order_check` | `categories` | `sort_order >= 0` |
| `products_status_check` | `products` | `status IN ('draft', 'active', 'archived')` |
| `variants_gtin_format_check` | `product_variants` | GTIN is NULL or numeric with length 8/12/13/14 |
| `variants_status_check` | `product_variants` | `status IN ('draft', 'active', 'archived')` |
| `variants_sort_order_check` | `product_variants` | `sort_order >= 0` |
| `attr_defs_type_check` | `attribute_definitions` | `type IN ('text', 'integer', 'decimal', 'boolean', 'date', 'select', 'multiselect')` |
| `attr_defs_scope_check` | `attribute_definitions` | `scope IN ('product', 'variant', 'both')` |
| `attr_defs_status_check` | `attribute_definitions` | `status IN ('active', 'archived')` |
| `attr_defs_sort_order_check` | `attribute_definitions` | `sort_order >= 0` |
| `attr_options_status_check` | `attribute_options` | `status IN ('active', 'archived')` |
| `attr_options_sort_order_check` | `attribute_options` | `sort_order >= 0` |
| `product_attr_values_one_value_check` | `product_attribute_values` | At most one typed value column non-null |
| `variant_attr_values_one_value_check` | `variant_attribute_values` | At most one typed value column non-null |
| `media_size_check` | `product_media` | `size_bytes >= 0` |
| `media_width_check` | `product_media` | `width IS NULL OR width > 0` |
| `media_height_check` | `product_media` | `height IS NULL OR height > 0` |
| `media_checksum_format_check` | `product_media` | SHA-256 is exactly 64 hexadecimal characters |
| `media_sort_order_check` | `product_media` | `sort_order >= 0` |

MySQL does not permit a CHECK to reference the auto-increment Category `id`. The
stable triggers `categories_prevent_self_parent_insert` and
`categories_prevent_self_parent_update` therefore reject `parent_id = id`. They are
limited to direct self-parenting; arbitrary cycle detection remains application-level.

---

## 8. Key Indexes

| Index | Columns | Query Supported |
|---|---|---|
| `categories_company_parent_index` | `(company_id, parent_id)` | "Get child categories" |
| `categories_company_status_index` | `(company_id, status)` | "Get active/archived categories" |
| `categories_company_sort_index` | `(company_id, sort_order)` | "Categories ordered by sort" |
| `products_company_status_index` | `(company_id, status)` | "Filter products by status" |
| `products_company_name_index` | `(company_id, name)` | "Search products by name" |
| `products_company_updated_index` | `(company_id, updated_at)` | "Recent products" |
| `variants_company_product_index` | `(company_id, product_id)` | "Get variants for product" |
| `variants_company_status_index` | `(company_id, status)` | "Filter variants by status" |
| `variants_product_sort_index` | `(product_id, sort_order)` | "Variants ordered by sort" |
| `category_product_company_product_index` | `(company_id, product_id)` | "Get categories for product" |
| `category_product_company_category_index` | `(company_id, category_id)` | "Get products in category" |
| `attr_defs_company_type_index` | `(company_id, type)` | "Filter definitions by type" |
| `attr_defs_company_scope_index` | `(company_id, scope)` | "Filter definitions by scope" |
| `attr_options_company_definition_index` | `(company_id, attribute_definition_id)` | "Get options for definition" |
| `product_attr_values_company_product_index` | `(company_id, product_id)` | "Get attributes for product" |
| `product_attr_values_company_def_index` | `(company_id, attribute_definition_id)` | "Get all products with attribute" |
| `product_attr_values_def_option_index` | `(attribute_definition_id, value_option_id)` | "Get products with option value" |
| `variant_attr_values_company_variant_index` | `(company_id, product_variant_id)` | "Get attributes for variant" |
| `variant_attr_values_company_def_index` | `(company_id, attribute_definition_id)` | "Get all variants with attribute" |
| `variant_attr_values_def_option_index` | `(attribute_definition_id, value_option_id)` | "Get variants with option value" |
| `media_company_product_index` | `(company_id, product_id)` | "Get media for product" |
| `media_company_variant_index` | `(company_id, product_variant_id)` | "Get media for variant" |
| `media_company_checksum_index` | `(company_id, checksum_sha256)` | "Deduplicate by checksum" |
| `media_product_sort_index` | `(product_id, sort_order)` | "Product images ordered" |
| `media_variant_sort_index` | `(product_variant_id, sort_order)` | "Variant images ordered" |
| `products_company_id_default_variant_index` | `(company_id, id, default_variant_id)` | Default Variant owner FK and lookup |
| `products_company_id_primary_media_index` | `(company_id, id, primary_media_id)` | Product primary-media owner FK |
| `variants_company_product_id_primary_media_index` | `(company_id, product_id, id, primary_media_id)` | Variant primary-media owner FK |

---

## 9. Circular/Dependent FK Strategy

Products table has three pointer columns created as raw columns (no FK) in migration 2:
- `primary_category_id` → composite same-company FK added in migration 12
- `default_variant_id` → composite same-company + same-product FK added in migration 12
- `primary_media_id` → composite same-company + same-product FK added in migration 12

ProductVariants has one pointer column:
- `primary_media_id` → composite same-company + same-product + same-variant FK added in migration 12

All deferred FKs are added AFTER all referenced tables exist (migration 12). No `SET FOREIGN_KEY_CHECKS=0` used.

---

## 10. Database-Enforced Invariants

These are enforced by MySQL constraints and verified by schema tests:

1. Same-company Category parent (composite FK)
2. Same-company Variant→Product (composite FK)
3. Same-company Category→Product pivot (composite FK)
4. Same-company Option→Definition (composite FK)
5. Same-company AttributeValue→Product (composite FK)
6. Same-company AttributeValue→Variant (composite FK)
7. Same-company Select Option→Definition integrity (composite FK)
8. Same-company and same-Definition Multiselect Option integrity (composite FKs)
9. Same-company Media→Product (composite FK)
10. Same-company+Same-Product Media→Variant (composite FK with 3 columns)
11. Same-company and same-Product Default Variant pointer (composite FK)
12. Same-company Primary Category pointer (composite FK)
13. Same-company and same-Product Product primary-media pointer (composite FK)
14. Same-company, same-Product, and same-Variant Variant primary-media pointer (composite FK)
15. Direct Category self-parenting rejected (INSERT/UPDATE triggers)
16. Product slug unique per Company
17. SKU unique per Company
18. GTIN unique per Company; non-null values are numeric with length 8/12/13/14
19. Attribute code unique per Company
20. Option code unique per Definition
21. Duplicate Category assignment rejected
22. Duplicate Product attribute value rejected
23. Duplicate Variant attribute value rejected
24. At most one typed value column per attribute row
25. Status only within valid set
26. Depth within 0-5
27. Sort order non-negative
28. Media size non-negative and dimensions positive or null
29. Media SHA-256 uses exactly 64 hexadecimal characters

---

## 11. Application-Enforced Invariants (for R1.3–R1.9)

These cannot be enforced by MySQL constraints alone and must be implemented in Action/Services:

1. Product always has exactly one default Variant (transaction + row lock + `is_default` sync)
2. No Category cycles (depth validation + recursive check in Action)
3. Publication readiness checklist (Action validation before status transition)
4. Archived Product has no active Variants (cascade in Action transaction)
5. Last Variant cannot be deleted (row lock + count check in Action)
6. Default Variant cannot be archived without replacement (Action check)
7. Product primary media is product-level (`product_variant_id IS NULL`)
8. Primary Category also exists in `category_product` pivot (Action syncs)
9. Attribute type matches populated value column (Action validates against definition)
10. Required attributes present for publication (Action validates)
11. SKU/GTIN/slug normalization before write (Action applies normalizer)
12. GTIN check digit validation (Action validates)
13. `default_variant_id` always non-null post-creation (Action + integrity check CLI)
14. Owner pointers are cleared/replaced before hard deletion of their RESTRICT targets

---

## 12. Soft-Delete and Identifier Reuse

| Entity | Soft Delete Column | Status Column | Identifier Reuse Policy |
|---|---|---|---|
| `categories` | `deleted_at` | `status` (active/archived) | Archived preserves slug; only admin hard-delete frees it |
| `products` | `deleted_at` | `status` (draft/active/archived) | Archived/soft-deleted preserves slug and SKU references |
| `product_variants` | `deleted_at` | `status` (draft/active/archived) | Archived preserves SKU/GTIN normalized columns |
| `attribute_definitions` | No | `status` (active/archived) | Code is permanent; no reuse |
| `attribute_options` | No | `status` (active/archived) | No reuse while status=archived |
| `product_media` | `deleted_at` | No | Physical files cleaned by CLI, not web request |

All unique constraints use column values regardless of `deleted_at` state, so soft-deleted records continue to occupy identifiers. Hard-delete (CLI/admin only) physically removes the row and frees the identifier.

---

## 13. GTIN Uniqueness Strategy

MySQL's `UNIQUE(company_id, gtin)` allows multiple rows with `gtin IS NULL` (MySQL treats NULLs as distinct in UNIQUE indexes). Non-null GTIN values are unique per Company. `variants_gtin_format_check` restricts them to digits with length 8, 12, 13, or 14. Check-digit validation remains application-level. No partial unique index is needed.

Application-level (future R1.3 Actions): Before INSERT/UPDATE, query `SELECT 1 FROM product_variants WHERE company_id = ? AND gtin = ? AND id != ? LIMIT 1` inside the transaction for additional safety. Database constraint is the authoritative enforcement.

---

## 14. Decimal Precision

`value_decimal` columns use `DECIMAL(20, 4)` — up to 16 integer digits and 4 decimal places. This is sufficient for all practical catalog measurements (weights in tonnes, dimensions in meters, prices in any currency). The precision is not configurable in R1.2.

---

## 15. Status Values

| Entity | Allowed Values | Enforcement |
|---|---|---|
| `products.status` | `draft`, `active`, `archived` | CHECK constraint |
| `product_variants.status` | `draft`, `active`, `archived` | CHECK constraint |
| `categories.status` | `active`, `archived` | CHECK constraint |
| `attribute_definitions.status` | `active`, `archived` | CHECK constraint |
| `attribute_options.status` | `active`, `archived` | CHECK constraint |

Stored as `VARCHAR` (R0 convention: application-backed string enums, not MySQL ENUM type).

---

## 16. Rollback Order

Rollback reverses migration order (12 → 1):

```bash
php artisan migrate:rollback --step=12
```

Migration 12 explicitly drops deferred cyclic foreign keys and their support indexes. Migration 1 explicitly drops the two Category triggers before `Schema::dropIfExists('categories')`; the remaining table-local constraints are dropped with their tables.

Verified: rollback + re-migrate passes cleanly.

---

## 17. ER Diagram (Text)

```
companies ──┬── categories ──┬── categories (self-ref parent)
            │                └── category_product ── products
            │
            ├── products ──┬── product_variants ──┬── variant_attribute_values ── attribute_definitions
            │              │                      │                              └── attribute_options
            │              │                      └── product_media (variant_id)
            │              │
            │              ├── product_media (variant_id IS NULL)
            │              ├── product_attribute_values ── attribute_definitions
            │              │                           └── attribute_options
            │              ├── category_product ── categories
            │              └── product_attribute_value_options ── product_attribute_values
            │                                                 └── attribute_options
            │
            ├── attribute_definitions ── attribute_options
            │
            └── variant_attribute_value_options ── variant_attribute_values
                                                └── attribute_options
```

---

## 18. Schema Test Strategy

| Test file | Database behavior covered |
|---|---|
| `CatalogSchemaTest.php` | Table/column inventory and migration presence |
| `CatalogTenantConstraintTest.php` | Cross-tenant parent, Product, Variant, AttributeValue, Option, pivot, and Media rejection; Category self-parent triggers |
| `CatalogPointerIntegrityTest.php` | Primary Category, default Variant, Product primary media, and Variant primary media owner-safe pointers |
| `CatalogUniqueConstraintTest.php` | Slug, SKU, GTIN, Definition/Option code, assignment/value uniqueness, format checks, and soft-delete identifier reservation |
| `CatalogAttributeIntegrityTest.php` | Typed-value exclusivity plus select/multiselect Option-to-Definition integrity |
| `CatalogMediaIntegrityTest.php` | Product/Variant ownership, size/dimension checks, and SHA-256 format |
| `CatalogForeignKeyTest.php` | `information_schema` verification of composite FK column maps, supporting unique keys, critical indexes, CHECK constraints, and triggers |

The default suite and focused catalog suite both run against the dedicated MySQL
database `nordipass_testing`. The test bootstrap rejects other drivers and rejects
database names without the `_testing` suffix. Run the complete suite with:

```bash
php artisan test
```

---

## 19. References

- **Domain definition:** [CATALOG_DOMAIN.md](CATALOG_DOMAIN.md)
- **Architecture decisions:** [CATALOG_DECISIONS.md](CATALOG_DECISIONS.md)
- **R1 scope:** [R1_CATALOG_SCOPE.md](R1_CATALOG_SCOPE.md)
- **MySQL test configuration:** `phpunit.mysql.xml` (`nordipass_testing`)
- **Default test configuration:** `phpunit.xml` (MySQL, `nordipass_testing`)
- **Environment:** `.env.testing` (local credentials, git-ignored)
