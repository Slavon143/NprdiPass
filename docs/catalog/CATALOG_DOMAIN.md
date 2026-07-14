# NordiPass R1 — Catalog Domain Definition

**Stage:** R1.1 — Catalog Domain Definition
**Status:** Complete
**Date:** 2026-07-14
**Scope:** R1 Core Catalog domain model before implementation

---

## 1. Domain Glossary

| Term | Definition |
|---|---|
| **Company** | A tenant in the shared-schema multi-tenant architecture. All catalog entities belong to exactly one Company. |
| **Catalog** | The collection of Products, Variants, Categories, Attributes, and Media owned by a Company. |
| **Product** | A commercial entity that groups one or more Variants sharing common descriptive attributes. Products carry name, slug, brand, descriptions, and product-level media. |
| **ProductVariant** | A sellable or identifiable configuration of a Product. Every Product has at least one Variant. Variants hold SKU, GTIN, MPN, and variant-specific attributes. |
| **Default Variant** | The single Variant designated as the primary representation of a Product. Used for listings, fallback media, and mapping to external systems. |
| **SKU** | Stock Keeping Unit — a Company-unique alphanumeric identifier on a Variant. Case-insensitive, trim-normalized. |
| **GTIN** | Global Trade Item Number (GTIN-8, GTIN-12/UPC, GTIN-13/EAN-13, GTIN-14). Optional identifier on a Variant. Digit-only with check digit validation. |
| **MPN** | Manufacturer Part Number — an optional free-text identifier on a Variant. |
| **Category** | A hierarchical classification within a Company's catalog tree. Products may belong to multiple Categories. |
| **Primary Category** | The main Category for a Product, used for default navigation, breadcrumbs, and SEO intent. Either mandatory (active) or optional (draft). Stored as `products.primary_category_id`. |
| **AttributeDefinition** | A Company-scoped schema definition for a product characteristic: name, code, data type, scope, validation rules. |
| **AttributeOption** | A predefined choice value for select/multiselect AttributeDefinitions. |
| **AttributeValue** | The actual value assigned to a specific Product or Variant for a given AttributeDefinition. Stored in separate `product_attribute_values` and `variant_attribute_values` tables with typed columns. |
| **ProductMedia** | An image file associated with a Product or Variant. Primary image tracked via FK on the owning entity. |
| **Draft** | A Product lifecycle status indicating the item is being created or edited and is not yet visible in the active catalog. |
| **Active** | A Product lifecycle status indicating the item is published and visible in catalog listings and via API. |
| **Archived** | A Product lifecycle status indicating the item is hidden from active catalog and API, preserved for historical reference and potential restoration. |
| **Publication Readiness** | The set of mandatory conditions a Product must satisfy before it can transition from draft to active. |
| **Tenant** | Synonym for Company in the context of multi-tenant isolation. |

---

## 2. Aggregate Boundaries

### Product Aggregate

The Product is the primary aggregate root for catalog mutations.

```
Product Aggregate
├── Product (aggregate root)
│   ├── Product-level AttributeValues (product_attribute_values)
│   ├── ProductMedia (product-level: variant_id IS NULL)
│   └── Category assignments (category_product pivot)
└── ProductVariants (cascaded within aggregate)
    ├── Variant-level AttributeValues (variant_attribute_values)
    └── ProductMedia (variant-level: variant_id IS NOT NULL)
```

**Rules:**
- Creating a Product must atomically create its default Variant in the same DB transaction.
- Archiving a Product archives all its Variants.
- Restoring a Product restores the default Variant to its previous active state.
- Setting the default Variant is a Product-aggregate operation.
- Deleting a Variant (last-variant check) is a Product-aggregate operation.

### Category Aggregate (independent)

Categories form a self-contained hierarchy tree. They can be modified independently of Products.

### AttributeDefinition Aggregate (independent)

AttributeDefinitions and their AttributeOptions form an independent aggregate. Changes to definitions do not cascade to existing values.

---

## 3. Core Entity Definitions

### 3.1 Product

**Conceptual fields:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | unsigned bigint | auto | Internal primary key (not exposed in API) |
| `uuid` | uuid | auto | Public route/model binding identifier (HasUuid) |
| `company_id` | unsigned bigint | required | FK → companies, server-set from CurrentCompany |
| `name` | string (255) | required | Display name |
| `slug` | string (255) | auto | URL-safe identifier, unique per Company |
| `slug_normalized` | string (255) | auto | `mb_strtolower(trim(slug))` for `UNIQUE(company_id, slug_normalized)` enforcement |
| `short_description` | text (500) | optional | Brief summary |
| `description` | text (10000) | optional | Full descriptive content |
| `brand` | string (255) | optional | Brand name (free text) |
| `manufacturer` | string (255) | optional | Manufacturer name (free text) |
| `status` | enum | required | `draft`, `active`, `archived` |
| `published_at` | timestamp | optional | Timestamp of first activation; preserved across archive/restore cycles |
| `default_variant_id` | unsigned bigint | nullable | FK → product_variants. Nullable during creation within transaction; MUST be non-null after the create Action commits. |
| `primary_category_id` | unsigned bigint | nullable | FK → categories. Required for active Products, optional for draft. |
| `primary_media_id` | unsigned bigint | nullable | FK → product_media. Points to the product-level primary image. Managed by the Action layer; nullable if no product image set. |
| `created_by` | unsigned bigint | required | FK → users |
| `updated_by` | unsigned bigint | nullable | FK → users |
| `created_at` | timestamp | auto | |
| `updated_at` | timestamp | auto | |
| `deleted_at` | timestamp | auto | Soft delete (administrative only) |

**What NOT to store on Product:** SKU, GTIN, MPN, variant-specific attributes, price, stock.

**Examples:**

Product with variants:
```
Product: "Work Glove Pro" (brand: "SafeHand", manufacturer: "SafeHand AB")
  Default Variant: "Black / M" — SKU: WG-BLACK-M, GTIN: 7312345678901
  Variant: "Black / L" — SKU: WG-BLACK-L, GTIN: 7312345678902
  Variant: "Yellow / M" — SKU: WG-YELLOW-M, GTIN: 7312345678903
```

Simple product:
```
Product: "Fire Extinguisher 6 kg" (brand: "FireSafe")
  Default Variant: "Default" — SKU: FS-EXT-6KG, GTIN: 7312345678999
```

### 3.2 ProductVariant

**Conceptual fields:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | unsigned bigint | auto | Internal primary key |
| `uuid` | uuid | auto | Public identifier (HasUuid) |
| `product_id` | unsigned bigint | required | FK → products |
| `company_id` | unsigned bigint | required | Denormalized, FK → companies. Always set from parent Product's company_id |
| `name` | string (255) | optional | Explicit variant label; auto-generated from attributes if empty |
| `sku` | string (100) | optional | Display SKU |
| `sku_normalized` | string (100) | auto | `mb_strtoupper(trim(sku))` for `UNIQUE(company_id, sku_normalized)` |
| `gtin` | varchar(14) | nullable | GTIN-8/12/13/14, digit-only with check digit validation |
| `mpn` | string (100) | nullable | Manufacturer Part Number, trim-normalized |
| `status` | enum | required | `draft`, `active`, `archived` |
| `is_default` | bool | required | `true` for the single default Variant per Product. Application-level + `products.default_variant_id` FK enforcement. |
| `primary_media_id` | unsigned bigint | nullable | FK → product_media. Points to this variant's primary image. Managed by Action layer; nullable if no variant image set. |
| `sort_order` | unsigned int | nullable | Display ordering, default 0 |
| `created_by` | unsigned bigint | required | FK → users |
| `created_at` | timestamp | auto | |
| `updated_at` | timestamp | auto | |
| `deleted_at` | timestamp | auto | Soft delete (administrative) |

**Variant display name resolution:**
```
IF variant.name IS NOT NULL AND variant.name != ''
  → display_name = variant.name
ELSE
  → display_name = variant_attributes JOINED BY " / " ordered by definition.sort_order
  → If no variant attributes exist: display_name = "Default"
```

### 3.3 Default Variant Storage and Invariants

**Storage decision:** `products.default_variant_id` — **nullable FK** to `product_variants`.

**Transaction-based integrity:**

| Phase | `default_variant_id` state |
|---|---|
| Before Product creation | N/A |
| Inside CreateProduct Action transaction | INSERT product (default_variant_id = NULL) → INSERT default Variant → UPDATE product SET default_variant_id = new Variant ID |
| After transaction commits | Always non-null for every Product |
| External access | Never observable as NULL (never read mid-transaction) |

**Why nullable FK:**
- MySQL does not support deferrable foreign key constraints.
- A NOT NULL FK to Variants creates a chicken-and-egg problem: Product requires Variant ID before insertion; Variant requires Product ID before insertion.
- The solution: both INSERTs in one transaction, with `default_variant_id` set to NULL temporarily, updated to the real ID before commit.
- Application-level invariant: after any successful Product Action commits, `default_variant_id` is always non-null.
- A DB-level NOT NULL constraint is not possible without deferrable FKs; an application-level integrity check command can detect violations.

**Default Variant invariants:**
- Every Product has exactly one default Variant at all times (post-creation).
- The default Variant belongs to the same Product (`variant.product_id = product.id`).
- The default Variant belongs to the same Company (`variant.company_id = product.company_id`).
- `products.default_variant_id` points to the variant where `variants.is_default = true`.
- The default Variant cannot be archived while the Product is `active`.
- The default Variant cannot be archived unless another Variant is promoted first.
- The last Variant of a Product cannot be deleted.
- Changing the default Variant: row lock on Product, validate target Variant belongs to same Product, update both `products.default_variant_id` and toggle `is_default` on old+new Variants in one transaction.

### 3.4 Primary Category Storage

**Storage decision: `products.primary_category_id` (nullable FK) + `category_product` pivot.**

The primary Category is stored as a direct FK on the Product row. Additional (non-primary) Categories are stored in the `category_product` pivot.

| Field | Where | Purpose |
|---|---|---|
| `products.primary_category_id` | Product row | Primary Category (nullable, FK → categories) |
| `category_product` | Pivot table | All additional (secondary) Category assignments |

**Rules:**
- `primary_category_id` points to an existing Category belonging to the same Company.
- If `primary_category_id` is set, the Category SHOULD also exist as a row in `category_product` (application-level consistency).
- A draft Product may have `primary_category_id = NULL`.
- An active Product MUST have a non-null `primary_category_id` (hard gate in publication readiness).
- When `primary_category_id` is changed, the old primary is NOT automatically removed from `category_product` — the operator decides.
- When the primary Category is archived, the Product loses its primary (`primary_category_id` is set to NULL by the Action). The Category remains in `category_product` if it was there.
- Exactly-one-primary-per-Product is enforced by the single FK column — no partial index needed.

**Rejected: `category_product.is_primary` boolean with generated-column uniqueness.**
- MySQL 8.0+ supports generated columns and functional indexes via `JSON_UNIQUE` or `UNIQUE INDEX` on a virtual column, but the pattern is fragile: `UNIQUE INDEX (product_id, (CASE WHEN is_primary = 1 THEN 1 END))` is not supported in MySQL (functional index expressions differ from PostgreSQL).
- The `primary_category_id` FK approach is simpler, performs better, requires no generated column, and makes "get the primary category for this product" a single column read without a WHERE clause scan.

### 3.5 Primary Media Storage

**Storage decision: FK columns on the owning entity.**

| Field | Entity | Purpose |
|---|---|---|
| `products.primary_media_id` | Product | Points to the product-level primary image (nullable FK → product_media) |
| `product_variants.primary_media_id` | Variant | Points to the variant-level primary image (nullable FK → product_media) |

**Rules:**
- These FKs are nullable — a Product/Variant can exist without a primary image.
- The FK target row must have matching `product_id` and (for variants) matching `variant_id`.
- Application-level validation ensures the media row belongs to the same entity before setting the FK.
- MySQL does not support conditional FK validation (checking variant_id matches), so this is enforced by Action classes.
- When primary media is deleted (soft-delete), the Action sets the FK to NULL and optionally promotes the next image by sort_order.
- Exactly-one-primary per (product, null-variant) and per (product, specific-variant) is enforced by the single FK column — no partial unique index needed.
- The `is_primary` boolean column on `product_media` is removed entirely — it is redundant with the FK.

---

## 4. Product Lifecycle

### 4.1 Statuses

| Status | Meaning |
|---|---|
| `draft` | Product is being created/edited. Not visible in active catalog. Can have incomplete data. |
| `active` | Product is published. Visible in catalog listings and API. Must satisfy publication readiness. |
| `archived` | Product is hidden from active catalog. Preserved for historical/external references. Can be restored. |

**No separate `inactive` status.** `published_at` captures whether the product was ever active.

### 4.2 State Transitions

| From | To | Permission | Constraints | Audit Event | Idempotent |
|---|---|---|---|---|---|
| `draft` | `active` | `catalog.publish` | Publication readiness checklist passed; default Variant non-archived; primary_category_id non-null | `catalog.product.activated` | Yes |
| `draft` | `archived` | `catalog.archive` | Product is `draft` | `catalog.product.archived` | Yes |
| `active` | `archived` | `catalog.archive` | Product is `active`; all Variants archived; relationships preserved | `catalog.product.archived` | Yes |
| `archived` | `draft` | `catalog.archive` | Product is `archived` | `catalog.product.restored` | No (transitions to draft) |
| `archived` | `active` | `catalog.publish` AND `catalog.archive` | Product was previously `active`; publication readiness passes again | `catalog.product.restored` | Yes |

**Transition behavior:**
- `draft → active`: Sets `published_at` to now (if first activation). Preserved across subsequent cycles.
- `active → archived`: Archives all Variants. Category relations, attributes, and media are preserved.
- `archived → draft`: Variants remain archived; manual Variant restoration follows.
- `archived → active`: Restores default Variant to active. Other Variants remain archived.

**Forbidden:** `active → draft`, direct deletion from `active`.

### 4.3 Publication Readiness

| # | Requirement | Status (Draft) | Status (Active) | Level |
|---|---|---|---|---|
| 1 | `name` is non-empty | OPTIONAL | REQUIRED | Hard gate |
| 2 | `slug` is present, non-empty, unique within Company | OPTIONAL (auto-generated) | REQUIRED | Hard gate |
| 3 | At least one Variant exists | REQUIRED (auto-created) | REQUIRED | Hard gate |
| 4 | Default Variant is assigned (`default_variant_id` non-null) | REQUIRED (post-creation) | REQUIRED | Hard gate |
| 5 | Default Variant is not archived | REQUIRED | REQUIRED | Hard gate |
| 6 | `primary_category_id` is non-null | OPTIONAL | REQUIRED | Hard gate |
| 7 | No required product-level attributes are empty | OPTIONAL | REQUIRED | Hard gate (only if definition.required = true) |
| 8 | No required variant-level attributes are empty on any active Variant | OPTIONAL | OPTIONAL for draft | Hard gate (only if definition.required = true, checked on default Variant) |
| 9 | SKU on default Variant | OPTIONAL | RECOMMENDED | Soft gate |
| 10 | `primary_media_id` is non-null | OPTIONAL | RECOMMENDED | Soft gate |

**Hard gates (1–8)**: Block activation. Validation returns a list of failed gates.

**Soft gates (9–10)**: UI warns; API may include a `warnings` field. Do not block activation.

---

## 5. Variant Lifecycle

### 5.1 Variant Statuses

- Active Product can have archived Variants (discontinued configurations).
- Draft Product — all Variants are draft.
- Active Product must have at least one active Variant (the default).
- Archived Product — all Variants are archived.

### 5.2 Variant State Transitions

| From | To | Constraint |
|---|---|---|
| `draft` | `active` | Product must be active or being activated simultaneously |
| `active` | `archived` | Cannot archive default Variant of active Product without promotion; cannot archive last active Variant of active Product |
| `draft` | `archived` | Allowed |
| `archived` | `active` | Allowed (e.g., reintroduction) |
| `archived` | `draft` | Allowed |

### 5.3 Variant Deletion

- Soft-delete (`deleted_at`), identical to R0 pattern.
- User-facing operations use `archive` (status change), not delete.
- Last Variant cannot be deleted.
- Default Variant cannot be deleted — promote first.
- Archived SKU remains in `sku_normalized` column → cannot be reused (see identifier reuse policy).

---

## 6. Category Model

### 6.1 Category Hierarchy

- Adjacency list with `parent_id` self-referencing FK and computed `depth` column.
- Parent must belong to the same Company.
- Cycles forbidden (application-level: depth validation + recursive check in transaction).
- Maximum depth: 5 levels (configurable via `CATALOG_MAX_CATEGORY_DEPTH`).

### 6.2 Category Fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | unsigned bigint | auto | |
| `uuid` | uuid | auto | HasUuid |
| `company_id` | unsigned bigint | required | FK → companies |
| `parent_id` | unsigned bigint | nullable | Self-referencing FK. NULL for root. |
| `depth` | unsigned int | auto | 0 for root, `parent.depth + 1` for children |
| `name` | string (255) | required | |
| `slug` | string (255) | required | Unique per Company: `UNIQUE(company_id, slug_normalized)` |
| `slug_normalized` | string (255) | auto | `mb_strtolower(trim(slug))` |
| `description` | text (1000) | optional | |
| `sort_order` | unsigned int | nullable | Default 0 |
| `status` | enum | required | `active`, `archived` |
| `created_at` | timestamp | auto | |
| `updated_at` | timestamp | auto | |
| `deleted_at` | timestamp | auto | Administrative soft-delete only |

### 6.3 Product–Category Relationship

- **Primary Category:** `products.primary_category_id` (FK, nullable) — exactly one per active Product.
- **Additional Categories:** Many-to-many via `category_product` pivot table.

**Pivot structure (`category_product`):**

| Field | Type | FK | Notes |
|---|---|---|---|
| `category_id` | unsigned bigint | categories(id) | |
| `product_id` | unsigned bigint | products(id) | |
| `created_at` | timestamp | | |

No `is_primary` column — primary is tracked via `products.primary_category_id`.

- A Product may exist without any categories in `draft`.
- An active Product MUST have a `primary_category_id`.
- Any Category in `category_product` may also be set as primary (the Action syncs both).
- Archiving a Category that is a Product's primary sets `products.primary_category_id = NULL` on that Product.

### 6.4 Category Archive Policy

**Categories are archived, not soft-deleted by user operations.** `status = 'archived'` is the user-facing removal. `deleted_at` is administrative only.

**Explicit operations — no implicit side effects:**

| Operation | Precondition | Effect |
|---|---|---|
| **Archive Category** | Category has **no active children** | Set `status = 'archived'`. Products keep their Category assignments. `products.primary_category_id` references set to NULL. |
| **Archive Category (with children)** | — | **REJECTED** with validation error listing active child Categories. Operator must first move or archive children. |
| **Archive Category (is primary for active Products)** | — | **REJECTED** with warning listing affected Products. Operator must first reassign primary Categories. |
| **Move Category** | Target parent is same Company, not self, not a descendant | Set `parent_id`, recompute `depth`. Validated in transaction with cycle detection. |
| **Restore Category** | Category is `archived` | Set `status = 'active'`. Parent relationship NOT restored; operator must explicitly move. |
| **Delete Category (soft)** | Category is `archived`, no active Products using it as primary | Set `deleted_at`. Pivot rows preserved for history. |

**Rationale:** Implicitly reparenting children to root as a side effect of archive is surprising behavior and can silently break catalog structure. Instead, the operator must make explicit decisions about children before archiving.

---

## 7. Attribute Model

### 7.1 AttributeDefinition

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | unsigned bigint | auto | |
| `uuid` | uuid | auto | HasUuid |
| `company_id` | unsigned bigint | required | FK → companies |
| `name` | string (255) | required | |
| `code` | string (100) | required | Machine-readable, unique per Company: `UNIQUE(company_id, code)` |
| `description` | text (500) | optional | |
| `type` | enum | required | `text`, `integer`, `decimal`, `boolean`, `date`, `select`, `multiselect` |
| `scope` | enum | required | `product`, `variant`, `both` |
| `unit` | string (50) | nullable | Free-text (e.g., `kg`, `mm`, `°C`) |
| `required` | bool | required | Default `false`. If `true`, blocks publication when value missing. |
| `filterable` | bool | required | Default `false` |
| `searchable` | bool | required | Default `false` |
| `sort_order` | unsigned int | nullable | Default 0 |
| `status` | enum | required | `active`, `archived` |
| `validation_rules` | json | nullable | Min/max for numeric, regex for text |
| `created_at` | timestamp | auto | |
| `updated_at` | timestamp | auto | |

**Attribute types:**

| Type | Storage Column | MySQL Type | Validation |
|---|---|---|---|
| `text` | `value_text` | varchar(1000) | Max length |
| `integer` | `value_integer` | bigint signed | Integer range |
| `decimal` | `value_decimal` | decimal(20,4) | Precision/scale |
| `boolean` | `value_boolean` | tinyint(1) | 0 or 1 |
| `date` | `value_date` | date | ISO 8601 |
| `select` | `value_option_id` | unsigned bigint (FK → attribute_options) | Single existing option |
| `multiselect` | (pivot table) | `attribute_value_options` pivot | One or more existing options |

**`measurement` type NOT included in R1.** `decimal` + free-text `unit` on AttributeDefinition is sufficient.

### 7.2 AttributeOption

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | unsigned bigint | auto | |
| `attribute_definition_id` | unsigned bigint | required | FK → attribute_definitions |
| `company_id` | unsigned bigint | required | Denormalized, FK → companies |
| `label` | string (255) | required | |
| `code` | string (100) | required | Unique per definition: `UNIQUE(attribute_definition_id, code)` |
| `sort_order` | unsigned int | nullable | Default 0 |
| `status` | enum | required | `active`, `archived` |
| `created_at` | timestamp | auto | |
| `updated_at` | timestamp | auto | |

### 7.3 AttributeValue — Two Entity-Specific Tables

**Decision: Split into `product_attribute_values` and `variant_attribute_values` instead of a single polymorphic table.**

This provides real foreign keys to `products` and `product_variants`, tenant-safe cascading, and no morph type strings.

**Table: `product_attribute_values`**

| Column | Type | FK | Notes |
|---|---|---|---|
| `id` | unsigned bigint | — | |
| `company_id` | unsigned bigint | companies(id) | Denormalized, set from Product |
| `attribute_definition_id` | unsigned bigint | attribute_definitions(id) | |
| `product_id` | unsigned bigint | products(id) | Cascade on product delete |
| `value_text` | varchar(1000) | — | For type=text |
| `value_integer` | bigint | — | For type=integer |
| `value_decimal` | decimal(20,4) | — | For type=decimal |
| `value_boolean` | tinyint(1) | — | For type=boolean |
| `value_date` | date | — | For type=date |
| `value_option_id` | unsigned bigint | attribute_options(id) | For type=select |
| `created_at` | timestamp | — | |
| `updated_at` | timestamp | — | |

**Table: `variant_attribute_values`**

| Column | Type | FK | Notes |
|---|---|---|---|
| `id` | unsigned bigint | — | |
| `company_id` | unsigned bigint | companies(id) | Denormalized, set from Variant/Product |
| `attribute_definition_id` | unsigned bigint | attribute_definitions(id) | |
| `product_variant_id` | unsigned bigint | product_variants(id) | Cascade on variant delete |
| `value_text` | varchar(1000) | — | |
| `value_integer` | bigint | — | |
| `value_decimal` | decimal(20,4) | — | |
| `value_boolean` | tinyint(1) | — | |
| `value_date` | date | — | |
| `value_option_id` | unsigned bigint | attribute_options(id) | |
| `created_at` | timestamp | — | |
| `updated_at` | timestamp | — | |

**Multiselect values are stored in a separate pivot table: `attribute_value_options`**

| Column | Type | FK | Notes |
|---|---|---|---|
| `attribute_value_id` | unsigned bigint | product_attribute_values(id) **OR** variant_attribute_values(id) | Polymorphic via two FKs: `product_attribute_value_id` (nullable) + `variant_attribute_value_id` (nullable); exactly one non-null |
| `attribute_option_id` | unsigned bigint | attribute_options(id) | |
| `created_at` | timestamp | — | |

**Constraint:** `CHECK (product_attribute_value_id IS NOT NULL OR variant_attribute_value_id IS NOT NULL)` and `CHECK (NOT (product_attribute_value_id IS NOT NULL AND variant_attribute_value_id IS NOT NULL))`.

**Rejected: JSON array in `value_options` column.**
- No FK integrity on option IDs.
- JSON arrays cannot be efficiently joined or filtered with standard SQL.
- A pivot table with real FKs guarantees referential integrity and simplifies queries.

**Rejected: Single polymorphic table.**
- No real FK to `products` or `product_variants` possible with `entity_type` + `entity_id`.
- Morph strings are fragile.
- Cascade/restrict behavior requires application-level handling.

**Rules:**
- `UNIQUE(attribute_definition_id, product_id)` on `product_attribute_values` — one value per attribute per Product.
- `UNIQUE(attribute_definition_id, product_variant_id)` on `variant_attribute_values` — one value per attribute per Variant.
- Exactly one typed value column is non-null per row (enforced at application level; CHECK constraint optional in R1).
- `company_id` is always set from the parent entity (Product for product_attribute_values; Variant → Product for variant_attribute_values).
- R1 allows in-place edits on active Products with audit trail. Full versioning deferred.

### 7.4 Attribute Scope

| Scope | Storage |
|---|---|
| `product` | Values in `product_attribute_values` |
| `variant` | Values in `variant_attribute_values` |
| `both` | Independent values in both tables (no inheritance). Same definition, two separate rows — one per entity. |

- Scope can only be changed when no values exist for that definition.
- No implicit inheritance — values are always explicitly set per entity.

### 7.5 Attribute Option Lifecycle

- An option assigned to any value cannot be hard-deleted.
- Unused options can be archived.
- Changing an option's `code` is allowed only when no values reference it.

---

## 8. Media Model

### 8.1 ProductMedia

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | unsigned bigint | auto | |
| `uuid` | uuid | auto | HasUuid |
| `company_id` | unsigned bigint | required | FK → companies |
| `product_id` | unsigned bigint | required | FK → products (always set) |
| `variant_id` | unsigned bigint | nullable | FK → variants; NULL = product-level media |
| `sort_order` | unsigned int | nullable | Default 0 |
| `alt_text` | string (500) | nullable | Accessibility text |
| `caption` | string (500) | nullable | |
| `original_filename` | string (255) | required | |
| `storage_path` | string (500) | required | Path within storage disk |
| `mime_type` | string (50) | required | |
| `size_bytes` | unsigned bigint | required | |
| `width` | unsigned int | nullable | |
| `height` | unsigned int | nullable | |
| `checksum_sha256` | char(64) | required | SHA-256 of file content |
| `uploaded_by` | unsigned bigint | required | FK → users |
| `created_at` | timestamp | auto | |
| `updated_at` | timestamp | auto | |
| `deleted_at` | timestamp | auto | Soft-delete |

**Primary image is tracked via FK on the owning entity — not via `is_primary` boolean.**

| Field | Entity | Meaning |
|---|---|---|
| `products.primary_media_id` | Product | Points to a product_media row where `variant_id IS NULL` |
| `product_variants.primary_media_id` | Variant | Points to a product_media row where `variant_id = this variant's id` |

**Invariants enforced by Action layer:**
- The target media row must have `product_id = product.id`.
- For variant primary: the target must have `variant_id = variant.id`.
- When primary media is soft-deleted, the FK is set to NULL by the Action.
- When an image is promoted to primary, the FK is updated. The old primary image is NOT deleted — just demoted.
- No partial unique index needed — the FK column itself is single-value.

**Data integrity on `product_media`:**
- `variant_id` (when set) must reference a variant whose `product_id` matches the media's `product_id`.
- `company_id` must match the Product's `company_id`.
- These are enforced by Actions (application-level) and validated before save. MySQL composite FKs on (product_id, variant_id) referencing product_variants(product_id, id) would require a composite FK that is complex and not worth the constraint overhead.
- As an added safety measure, a future `nordipass:verify-media-integrity` command can detect cross-entity violations.

**Alternative considered: separate `variant_media` table.**
- Rejected because: single-table queries return all images for a product; variant images are just filtered by `variant_id IS NOT NULL`. Separate tables would require UNION for "all images" queries.

### 8.2 Supported Image Formats (R1)

| Format | MIME Type | Extension |
|---|---|---|
| JPEG | `image/jpeg` | `.jpg`, `.jpeg` |
| PNG | `image/png` | `.png` |
| WEBP | `image/webp` | `.webp` |

SVG excluded (XSS risk). PDF/Documents deferred to R2+.

### 8.3 Media Lifecycle

| Action | Effect |
|---|---|
| Upload media | File stored, record created, audit event logged |
| Update metadata | Alt text, caption, sort order updated (file unchanged) |
| Replace file | New file, old file deleted, checksum updated |
| Delete media record | Record soft-deleted; if it was primary, FK set to NULL |
| Product archived | Media records preserved, files kept |
| Physical file cleanup | `nordipass:prune-orphan-media` — removes files whose records are soft-deleted and older than retention (default 30 days) |

### 8.4 File Storage

- Files stored on configured filesystem disk (`MEDIA_DISK=local` default, S3-ready).
- Storage path: `{company_uuid}/products/{product_uuid}/{media_uuid}.{ext}` or `.../variants/{variant_uuid}/{media_uuid}.{ext}`.
- Original filename NEVER used in storage path (security: path traversal prevention).
- Tenant isolation: path begins with `company_uuid`.

---

## 9. Tenant Ownership

### 9.1 Ownership Rules

| Entity | `company_id` source | Own `company_id` column? |
|---|---|---|
| Product | CurrentCompany at creation | Yes (PK table) |
| ProductVariant | Product's `company_id` at creation | **Yes** — denormalized, FK → companies |
| Category | CurrentCompany at creation | Yes |
| AttributeDefinition | CurrentCompany at creation | Yes |
| AttributeOption | Definition's `company_id` at creation | **Yes** — denormalized, FK → companies |
| AttributeValue (product) | Product's `company_id` | **Yes** — denormalized, FK → companies |
| AttributeValue (variant) | Variant's `company_id` | **Yes** — denormalized, FK → companies |
| ProductMedia | Product's `company_id` | **Yes** — denormalized, FK → companies |

**Rationale for denormalization (same as R0 for `company_invitations` and `personal_access_tokens`):**
- Every tenant query includes `WHERE company_id = ?` without relying on joins.
- Unique constraints (SKU, slug, code) include `company_id` without joining parent tables.
- Consistency with R0 architecture.

---

## 10. Identifiers, Normalization, and Reuse

### 10.1 Public Identifiers (Route Binding)

All catalog entities use **UUIDs** for public route binding via `HasUuid` trait (R0 convention).

| Entity | Route Key | Binding Pattern |
|---|---|---|
| Product | `uuid` | `{product:uuid}` |
| ProductVariant | `uuid` | `{variant:uuid}` |
| Category | `uuid` | `{category:uuid}` |
| AttributeDefinition | `uuid` | `{definition:uuid}` |
| ProductMedia | `uuid` | `{media:uuid}` |

Internal auto-increment IDs are never exposed in routes, API responses, or URLs.

### 10.2 Tenant-Scoped Route Binding

```php
$product = $currentCompany->products()->where('uuid', $uuid)->firstOrFail();
// Never: Product::where('uuid', $uuid)->firstOrFail();
```

### 10.3 Identifier Normalization

| Identifier | Trim | Unicode | Case | Whitespace | Allowed chars | Max length | Collation on indexed column |
|---|---|---|---|---|---|---|---|
| Product `slug` | Yes | `Str::slug()` → ASCII | `mb_strtolower` | Hyphens only | Alphanumeric + hyphen | 255 | `utf8mb4_unicode_ci` |
| Product `slug_normalized` | — (from slug) | Same | Lowercase stored | Hyphens only | Same as slug | 255 | `utf8mb4_bin` (exact match on UNIQUE) |
| Variant `sku` (display) | Yes | Preserved as-is | Preserved as-is | Internal spaces allowed | Alphanumeric + `-` `_` `.` | 100 | N/A (display only) |
| Variant `sku_normalized` | Yes (from sku) | NFC | `mb_strtoupper` | Collapsed to single space then stripped? **Removed entirely** | Same as sku, uppercased | 100 | `utf8mb4_bin` (exact match on UNIQUE) |
| Variant `gtin` | Yes | Digit-only filter | N/A | Removed | Digits only (0–9) | 14 | `utf8mb4_bin` (exact match) |
| Variant `mpn` | Yes | Preserved | Preserved | Preserved | Free text | 100 | N/A |
| Category `slug` | Yes | `Str::slug()` → ASCII | `mb_strtolower` | Hyphens only | Alphanumeric + hyphen | 255 | `utf8mb4_unicode_ci` |
| Attribute `code` | Yes | `Str::slug()` → ASCII | `mb_strtolower` | Underscores only | Lowercase alphanumeric + `_` | 100 | `utf8mb4_bin` (exact match on UNIQUE) |

**SKU normalization algorithm:**
```
$normalized = mb_strtoupper(trim($sku));
// Collapse multiple whitespace: preg_replace('/\s+/', ' ', $normalized)
// But: we strip spaces entirely for uniqueness match
$normalized_for_unique = preg_replace('/\s+/', '', $normalized);
```
For uniqueness enforcement, `sku_normalized` stores the space-stripped, uppercased, trimmed version. The user's original casing and spacing are preserved in the `sku` display column.

### 10.4 Identifier Reuse Policy

| Identifier | Active state | Archived state | Soft-deleted state | Can be reused? |
|---|---|---|---|---|
| Product `slug` | Unique via `UNIQUE(company_id, slug_normalized)` | Occupies the normalized value (cannot be reused) | Occupies the normalized value | **No** — never during retention period. Admin hard-delete (CLI only) frees it. |
| Variant `sku` | Unique via `UNIQUE(company_id, sku_normalized)` | Occupies the normalized value | Occupies the normalized value | **No** — never during retention period. |
| Variant `gtin` | Unique company-wide when non-null (application-level) | Occupies the value when non-null | Occupies the value when non-null | **No** — while record exists. Admin hard-delete frees it. |
| Variant `mpn` | No uniqueness constraint | No restriction | No restriction | **Yes** — no uniqueness enforced. |
| Category `slug` | Unique via `UNIQUE(company_id, slug_normalized)` | Occupies the normalized value | Occupies the normalized value | **No** — same policy as Product slug. |
| Attribute `code` | Unique via `UNIQUE(company_id, code)` | Occupies the value | N/A (attributes use archive status) | **No** — code is permanent. |

**Default policy:** Archive and soft-delete both preserve identifier uniqueness. A hard-deleted (physically removed) record frees the identifier, but hard-delete is CLI/administrative only and not available through UI. This is the safest default and prevents accidental reuse of identifiers that external systems may still reference.

**MySQL GTIN uniqueness implementation:** No `WHERE gtin IS NOT NULL` partial index exists in MySQL. Instead:
- Application-level validation: before INSERT/UPDATE, the Action queries `SELECT 1 FROM product_variants WHERE company_id = ? AND gtin = ? AND id != ? LIMIT 1` inside the transaction.
- A regular unique index `UNIQUE(company_id, gtin)` **cannot** be used because MySQL treats all-NULL values in a UNIQUE index as distinct (multiple rows can have `gtin IS NULL`), which is correct behavior for our case, BUT we also need NULLs to not conflict with each other.

**MySQL GTIN solution:** A standard `UNIQUE INDEX (company_id, gtin)` on the `product_variants` table. In MySQL:
- Multiple rows with `gtin = NULL` are allowed (MySQL treats NULLs as distinct in UNIQUE indexes).
- Two rows with the same non-null `(company_id, gtin)` pair are rejected.
This works exactly as desired without any partial index — it's MySQL's standard behavior for nullable columns in UNIQUE indexes.

---

## 11. Localization Strategy

**Decision: Single-language in R1.** All text content (`name`, `description`, `short_description`, `alt_text`, `caption`, `brand`, `manufacturer`, Attribute names/labels, Category names) is stored in plain varchar/text columns. No translation tables, no JSON translations, no polymorphic translation structures in R1.

**Future migration path (R2+):** Translatable columns can be extracted into a polymorphic `translations` table `(entity_type, entity_id, field, locale, value)`. The existing columns become the default-locale fallback. Entity IDs and relations remain unchanged. This is trivially non-blocking.

---

## 12. Permissions

### 12.1 New Company Permissions

```php
case CatalogView = 'catalog.view';
case CatalogCreate = 'catalog.create';
case CatalogUpdate = 'catalog.update';
case CatalogArchive = 'catalog.archive';
case CatalogPublish = 'catalog.publish';
case CatalogManageCategories = 'catalog.manage_categories';
case CatalogManageAttributes = 'catalog.manage_attributes';
case CatalogManageMedia = 'catalog.manage_media';
```

No separate `catalog.delete` — hard-delete is administrative only. Archive is the user-facing removal.

### 12.2 Complete Permission Matrix

| Permission | Owner | Admin | Editor | Viewer |
|---|---|---|---|---|
| `catalog.view` | ALLOW | ALLOW | ALLOW | ALLOW |
| `catalog.create` | ALLOW | ALLOW | ALLOW | DENY |
| `catalog.update` | ALLOW | ALLOW | ALLOW | DENY |
| `catalog.archive` | ALLOW | ALLOW | DENY | DENY |
| `catalog.publish` | ALLOW | ALLOW | DENY | DENY |
| `catalog.manage_categories` | ALLOW | ALLOW | DENY | DENY |
| `catalog.manage_attributes` | ALLOW | ALLOW | DENY | DENY |
| `catalog.manage_media` | ALLOW | ALLOW | ALLOW | DENY |

**Editor rationale:**
- Can create/update products and manage media (content operations).
- Cannot publish, archive, or manage structural catalog elements (categories/attributes).
- Portal/Viewer remains read-only.

### 12.3 Authorization Flow (R0 pattern)

```
Route middleware → Controller → $user->can('catalog.publish', $product)
  → ProductPolicy::publish()
    → CompanyAuthorizer::allows(user, company, CatalogPublish)
      → CompanyPermissionMatrix::allows(role, permission)
```

### 12.4 Policies (Conceptual)

| Policy | Methods |
|---|---|
| `ProductPolicy` | `viewAny`, `view`, `create`, `update`, `archive`, `publish` |
| `ProductVariantPolicy` | `view`, `create`, `update`, `archive`, `setDefault` |
| `CategoryPolicy` | `viewAny`, `view`, `create`, `update`, `archive`, `move` |
| `AttributeDefinitionPolicy` | `viewAny`, `view`, `create`, `update`, `archive` |
| `ProductMediaPolicy` | `view`, `upload`, `update`, `delete` |

---

## 13. API Abilities

### 13.1 Sanctum Token Abilities

| Ability | Value | Covers |
|---|---|---|
| `catalog.read` | `catalog.read` | GET products, categories, attributes |
| `catalog.write` | `catalog.write` | POST/PATCH products and variants |
| `catalog.publish` | `catalog.publish` | Product activation |
| `catalog.categories.manage` | `catalog.categories.manage` | Category CRUD |
| `catalog.attributes.manage` | `catalog.attributes.manage` | Attribute definition/option CRUD |
| `catalog.media.manage` | `catalog.media.manage` | Media upload/update/delete |

### 13.2 API Authorization

Token ability + membership authorization **both must pass** (R0-consistent). An API token ability does NOT bypass CompanyPermission checks.

---

## 14. Audit Events

### 14.1 Catalog Audit Events (extend `AuditEvent` enum)

```php
// Categories
case CatalogCategoryCreated = 'catalog.category.created';
case CatalogCategoryUpdated = 'catalog.category.updated';
case CatalogCategoryMoved = 'catalog.category.moved';
case CatalogCategoryArchived = 'catalog.category.archived';
case CatalogCategoryRestored = 'catalog.category.restored';

// Products
case CatalogProductCreated = 'catalog.product.created';
case CatalogProductUpdated = 'catalog.product.updated';
case CatalogProductActivated = 'catalog.product.activated';
case CatalogProductArchived = 'catalog.product.archived';
case CatalogProductRestored = 'catalog.product.restored';

// Variants
case CatalogVariantCreated = 'catalog.variant.created';
case CatalogVariantUpdated = 'catalog.variant.updated';
case CatalogVariantDefaultChanged = 'catalog.variant.default_changed';
case CatalogVariantArchived = 'catalog.variant.archived';
case CatalogVariantRestored = 'catalog.variant.restored';

// Attributes
case CatalogAttributeCreated = 'catalog.attribute.created';
case CatalogAttributeUpdated = 'catalog.attribute.updated';
case CatalogAttributeArchived = 'catalog.attribute.archived';

// Media
case CatalogMediaUploaded = 'catalog.media.uploaded';
case CatalogMediaUpdated = 'catalog.media.updated';
case CatalogMediaDeleted = 'catalog.media.deleted';
```

All use `logTenant()` (tenant-scoped). Properties sanitized via `SensitiveDataSanitizer`. Never stored: file contents, raw request payloads, full descriptions (truncated labels only), checksums, credentials.

---

## 15. Search, Filters, and Listing

### 15.1 Searchable Fields (MySQL-based)

| Field | Search Type |
|---|---|
| Product `name` | `LIKE '%term%'` or MySQL FULLTEXT |
| Product `slug` | Exact or prefix |
| Variant `sku_normalized` | Exact or prefix (finds Product via JOIN) |
| Variant `gtin` | Exact |
| Variant `mpn` | Prefix |
| Product `brand` | Prefix |
| Product `manufacturer` | Prefix |

### 15.2 Filters

| Filter | Type |
|---|---|
| `status` | Enum: draft/active/archived |
| `category_id` | UUID (optionally include children) |
| `brand` | String prefix |
| `manufacturer` | String prefix |
| `created_from` / `created_to` | Date range |
| `updated_from` / `updated_to` | Date range |
| `has_variants` | Boolean |
| Attribute filters | Dynamic, only for `filterable=true` definitions |

### 15.3 Sorting

`name`, `created_at`, `updated_at`, `status`, `sku` (default Variant) — ASC/DESC.

### 15.4 Pagination

Default 25, maximum 100, parameter `per_page`.

---

## 16. Concurrency Protection

| Operation | Risk | Protection |
|---|---|---|
| Create Product with duplicate slug | Two requests with same `name` | `UNIQUE(company_id, slug_normalized)` |
| Create Variant with duplicate SKU | Two requests with same SKU | `UNIQUE(company_id, sku_normalized)` |
| Create Variant with duplicate GTIN | Two requests with same GTIN | `UNIQUE(company_id, gtin)` — MySQL allows multiple NULLs |
| Activate Product | Variant being archived concurrently | Row lock on Product during activation |
| Set default Variant | Two admins promote different Variants | Row lock on Product |
| Delete last Variant | Two concurrent deletes | Row lock on Product, count check in transaction |
| Move Category | Concurrent moves create cycle | Row lock on Category + parent, cycle detection |
| Upload media | Filename collision | UUID-based filenames, no collision |
| Set primary media | Two admins set different primaries | Row lock on owning entity (Product or Variant) |

---

## 17. Database Invariants

### 17.1 DB-Enforceable

| # | Invariant | Constraint |
|---|---|---|
| 1 | Product belongs to existing Company | FK `products.company_id → companies.id` |
| 2 | Variant belongs to existing Product | FK `product_variants.product_id → products.id` |
| 3 | Category parent same Company | Application-level + FK self-ref |
| 4 | SKU unique per Company | `UNIQUE(company_id, sku_normalized)` |
| 5 | Product slug unique per Company | `UNIQUE(company_id, slug_normalized)` |
| 6 | Category slug unique per Company | `UNIQUE(company_id, slug_normalized)` |
| 7 | Attribute code unique per Company | `UNIQUE(company_id, code)` |
| 8 | One value per attribute per Product | `UNIQUE(attribute_definition_id, product_id)` on `product_attribute_values` |
| 9 | One value per attribute per Variant | `UNIQUE(attribute_definition_id, product_variant_id)` on `variant_attribute_values` |
| 10 | GTIN unique per Company (non-null only) | `UNIQUE(company_id, gtin)` — MySQL allows multiple NULLs |
| 11 | Default Variant belongs to same Product | FK `products.default_variant_id → product_variants.id` (same Product validated by app) |
| 12 | Primary Category belongs to same Company | FK `products.primary_category_id → categories.id` (same Company validated by app) |
| 13 | Primary media belongs to same Product | FK `products.primary_media_id → product_media.id` (same entity validated by app) |
| 14 | Variant primary media belongs to same Variant | FK `product_variants.primary_media_id → product_media.id` (same entity validated by app) |

### 17.2 Application-Enforced

| # | Invariant | Protection |
|---|---|---|
| 15 | Exactly one default Variant per Product | Row lock + `default_variant_id` FK + `is_default` toggle in transaction |
| 16 | No Category cycles | Depth validation + recursive check in transaction |
| 17 | Publication readiness | Validation in activation Action before status transition |
| 18 | Archived Product has no active Variants | Cascade archive in transaction |
| 19 | Last Variant cannot be deleted | Row lock + count check in transaction |
| 20 | Default Variant cannot be archived without replacement | Row lock + check in transaction |
| 21 | Variant company_id matches Product company_id | Action sets from parent Product |
| 22 | Media variant_id belongs to same product_id | Action validates before save |
| 23 | Attribute value company_id matches entity | Action sets from parent entity |
| 24 | Primary media FK target matches entity (product_id, variant_id) | Action validates before setting FK |

---

## 18. Variant Display Name Resolution

```
IF variant.name IS NOT NULL AND variant.name != ''
  → display_name = variant.name
ELSE
  → display_name = variant_attributes JOINED BY " / " ordered by definition.sort_order
  → If no variant attributes exist: display_name = "Default"
```

---

## 19. Validation Limits

| Item | Default Limit | Configurable | Reason |
|---|---|---|---|
| Product.name | 255 chars | No (DB column) | Industry standard |
| Product.slug | 255 chars | No (DB column) | URL-safe, auto-generated |
| Product.short_description | 500 chars | No (DB column) | Summary field |
| Product.description | 10,000 chars | No (text type) | Rich content |
| Product.brand | 255 chars | No (DB column) | Free text identifier |
| Product.manufacturer | 255 chars | No (DB column) | Free text identifier |
| Variant.sku | 100 chars | No (DB column) | Industry standard for SKUs |
| Variant.mpn | 100 chars | No (DB column) | Free text identifier |
| Variant.gtin | 14 chars | No (DB column) | GTIN-14 max length |
| Category.name | 255 chars | No (DB column) | |
| Category.description | 1,000 chars | No (DB column) | |
| AttributeDefinition.name | 255 chars | No (DB column) | |
| AttributeDefinition.code | 100 chars | No (DB column) | Machine identifier |
| AttributeDefinition.unit | 50 chars | No (DB column) | Free-text unit label |
| AttributeOption.label | 255 chars | No (DB column) | |
| AttributeOption.code | 100 chars | No (DB column) | |
| AttributeValue.value_text | 1,000 chars | No (DB column) | |
| Media.alt_text | 500 chars | No (DB column) | Accessibility |
| Media.caption | 500 chars | No (DB column) | |
| Media.original_filename | 255 chars | No (DB column) | |
| Max Variants per Product | 100 | Yes (`CATALOG_MAX_VARIANTS_PER_PRODUCT`) | Prevents runaway creation |
| Max Categories per Product | 20 | Yes (`CATALOG_MAX_CATEGORIES_PER_PRODUCT`) | Prevents over-categorization |
| Max images per Product (total) | 50 | Yes (`CATALOG_MAX_MEDIA_PER_PRODUCT`) | Combined product + variant |
| Max images per Variant | 10 | Yes (`CATALOG_MAX_MEDIA_PER_VARIANT`) | Subset of per-product total |
| Max Category tree depth | 5 | Yes (`CATALOG_MAX_CATEGORY_DEPTH`) | Prevents deep nesting |
| Max AttributeDefinitions per Company | 500 | Yes (`CATALOG_MAX_ATTRIBUTES_PER_COMPANY`) | Performance bound |
| Max AttributeOptions per Definition | 200 | Yes (`CATALOG_MAX_OPTIONS_PER_DEFINITION`) | Prevents runaway select lists |
| Pagination default | 25 | Yes (API parameter) | Consistent with R0 |
| Pagination maximum | 100 | Yes (config) | Consistent with R0 |

---

## 20. Use-Case Inventory

| # | Use Case | Actor | Aggregate | Transaction | Audit Event |
|---|---|---|---|---|---|
| UC-01 | Create Category | admin+ | Category | No | `catalog.category.created` |
| UC-02 | Update Category | admin+ | Category | No | `catalog.category.updated` |
| UC-03 | Move Category | admin+ | Category | Yes | `catalog.category.moved` |
| UC-04 | Archive Category | admin+ | Category | No (blocked if active children or primary for active Products) | `catalog.category.archived` |
| UC-05 | Restore Category | admin+ | Category | No | `catalog.category.restored` |
| UC-06 | Create Product | editor+ | Product | Yes (Product + default Variant atomically) | `catalog.product.created` |
| UC-07 | Update Product | editor+ | Product | Yes | `catalog.product.updated` |
| UC-08 | Assign/Remove Categories | editor+ | Product | No | `catalog.product.updated` |
| UC-09 | Set Primary Category | editor+ | Product | No (updates `products.primary_category_id`) | `catalog.product.updated` |
| UC-10 | Create Variant | editor+ | Product | Yes | `catalog.variant.created` |
| UC-11 | Update Variant | editor+ | Product | Yes | `catalog.variant.updated` |
| UC-12 | Set Default Variant | admin+ | Product | Yes (locks Product, updates FK + toggles is_default) | `catalog.variant.default_changed` |
| UC-13 | Archive Variant | admin+ | Product | Yes | `catalog.variant.archived` |
| UC-14 | Restore Variant | admin+ | Product | Yes | `catalog.variant.restored` |
| UC-15 | Set Product Attributes | editor+ | Product | Yes | `catalog.product.updated` |
| UC-16 | Set Variant Attributes | editor+ | Product | Yes | `catalog.variant.updated` |
| UC-17 | Upload Product Image | editor+ | Product | No | `catalog.media.uploaded` |
| UC-18 | Upload Variant Image | editor+ | Product | No | `catalog.media.uploaded` |
| UC-19 | Set Primary Image | editor+ | Product | No (updates FK on owning entity) | `catalog.media.updated` |
| UC-20 | Delete Media | editor+ | Product | No (clears primary FK if needed) | `catalog.media.deleted` |
| UC-21 | Activate Product | admin+ | Product | Yes | `catalog.product.activated` |
| UC-22 | Archive Product | admin+ | Product | Yes | `catalog.product.archived` |
| UC-23 | Restore Product | admin+ | Product | Yes | `catalog.product.restored` |
| UC-24 | Search Products | viewer+ | N/A | No | None |
| UC-25 | Filter Products | viewer+ | N/A | No | None |
| UC-26 | View Product | viewer+ | N/A | No | None |
| UC-27 | Create AttributeDefinition | admin+ | AttributeDefinition | No | `catalog.attribute.created` |
| UC-28 | Update AttributeDefinition | admin+ | AttributeDefinition | No | `catalog.attribute.updated` |
| UC-29 | Archive AttributeDefinition | admin+ | AttributeDefinition | No | `catalog.attribute.archived` |
| UC-30 | Create AttributeOption | admin+ | AttributeDefinition | No | `catalog.attribute.updated` |
| UC-31 | Update AttributeOption | admin+ | AttributeDefinition | No | `catalog.attribute.updated` |
| UC-32 | Archive AttributeOption | admin+ | AttributeDefinition | No | `catalog.attribute.updated` |

---

## 21. State Diagrams

### Product State

```
     ┌────────┐
     │ draft  │
     └───┬──┬─┘
    activate│archive
         │  │
         ▼  ▼
     ┌───────┐  archive  ┌──────────┐
     │active │──────────▶│ archived │
     └───┬───┘           └────┬─────┘
         │      restore       │
         │   (→ active if     │
         │    was active)     │
         └────────────────────┘
```

### Variant State

```
     ┌────────┐
     │ draft  │
     └───┬────┘
    activate
         ▼
     ┌────────┐
     │ active │
     └───┬────┘
    archive
         ▼
     ┌──────────┐
     │ archived │
     └────┬─────┘
     restore
         ▼
     ┌────────┐ (or draft if product is draft)
     │ active │
     └────────┘
```

---

## 22. Future Compatibility Points

All future modules remain non-blocked by the chosen model:
- QR codes → reference Product/Variant UUID; Variant has SKU/GTIN for payload.
- DPP → attaches to Product; reads attributes, media, default Variant identifiers.
- Pricing → Variant-level (deferred); Product-level pricing is future decision.
- Inventory → Variant-level stock, warehouse FK on separate table.
- Fortnox → SKU mapping from Variant; GTIN for EAN article lookup.
- Documents → polymorphic attachment to Product, independent of media.
- Excel Import → SKU/GTIN on Variant enables deterministic dedup.
- AI Translations → translatable fields in plain columns, extractable for batch translation.
- Public Pages → slug uniqueness per Company enables clean URLs.
- Search Engine → searchable fields indexed; model supports extraction to Meilisearch/Elasticsearch.

---

## 23. Domain Acceptance Matrix

| Domain Area | Decision | Invariant | Implementation Stage |
|---|---|---|---|
| Product-Variant model | Product always has ≥1 Variant | `default_variant_id` always non-null post-creation | R1.2 (schema), R1.5, R1.6 |
| Default Variant storage | `products.default_variant_id` (nullable FK, set in transaction) | Row lock on Product for mutations | R1.2 |
| SKU | On Variant, normalized, Company-unique | `UNIQUE(company_id, sku_normalized)` | R1.2 |
| GTIN | On Variant, validated, optional | `UNIQUE(company_id, gtin)` — MySQL nullable behavior | R1.2 |
| MPN | On Variant, free text, optional | No uniqueness | R1.2 |
| Product slug | `UNIQUE(company_id, slug_normalized)` | Normalized column for exact match | R1.2 |
| Primary Category | `products.primary_category_id` FK + `category_product` pivot | Exactly one per active Product | R1.2, R1.5 |
| Categories | Adjacency list, max depth 5 | No cycles, explicit archive (block children) | R1.2 (schema), R1.4 |
| Category archive | Explicit: blocked if active children or primary for active Products | No implicit side effects | R1.4 |
| Attributes | `product_attribute_values` + `variant_attribute_values` tables | Real FKs, typed columns, UNIQUE per entity | R1.2 (schema), R1.7 |
| Multiselect | `attribute_value_options` pivot table | Real FKs on option IDs | R1.2, R1.7 |
| Attribute scope | product/variant/both — two independent tables | No inheritance, no morph strings | R1.7 |
| Media | Single `product_media` table, primary via FK on owner | `products.primary_media_id` + `variant.primary_media_id` | R1.2 (schema), R1.8 |
| Media lifecycle | Soft-delete records, separate file cleanup | `nordipass:prune-orphan-media` CLI | R1.8 |
| Product lifecycle | draft/active/archived (3 states) | Publication readiness (8 hard + 2 soft gates) | R1.5, R1.9 |
| Tenant ownership | Denormalized company_id on all, real FKs | Same-company enforcement at Action level | R1.2 |
| Identifiers | UUID route binding (HasUuid) | Consistent with R0 | R1.2 |
| Identifier reuse | Archive/soft-delete preserves uniqueness | Only admin hard-delete frees identifiers | R1.2 |
| Permissions | 8 new CompanyPermission values, ALLOW/DENY matrix | Editor: create/update/media; Admin+: publish/archive/structure | R1.3 |
| API abilities | 6 new ApiTokenAbility values | Token ability + membership both required | R1.11 |
| Audit events | 17 new AuditEvent values | Tenant-scoped via logTenant() | R1.3+ |
| Search | MySQL-based, exact/prefix/fulltext | Configurable searchable field flags | R1.10 |
| Pagination | 25 default, 100 max | R0 convention | R1.11 |
| Localization | Single language R1, varchar/text columns | No translation tables; schema-forward | N/A (future) |

---

## 24. Deferred from R1

### Billing & Commerce
- Prices (list, cost, sale), currencies, VAT, discounts, inventory, warehouses, suppliers, purchase orders, orders, carts, checkout.

### Documents & Digital
- Document management, PDF generation, QR codes, DPP, public storefront, custom domains.

### Integrations
- Fortnox, Excel import/export, bulk import/edit, webhooks.

### Intelligence
- AI text generation, AI translation, RAG search/chat, analytics, recommendations.

### Advanced Product Features
- Product relations, bundles/kits, version history, approval workflow, custom fields, cloning, mass status changes, scheduled publication.

### Localization
- Multi-language translations, locale-aware API, per-language slugs, unit conversion.

### Search
- Elasticsearch/Meilisearch, faceted search, autocomplete, synonyms, search analytics.

---

## 25. References

- **Decision records:** [CATALOG_DECISIONS.md](CATALOG_DECISIONS.md)
- **R1 scope:** [R1_CATALOG_SCOPE.md](R1_CATALOG_SCOPE.md)
- **R0 architecture:** [README.md](../../README.md), [API.md](../../docs/API.md)
