# NordiPass R1 — Catalog Architecture Decisions

**Stage:** R1.1
**Date:** 2026-07-14

---

## Decision 1: Product Always Has a Variant

### Context

Two possible models:
1. `Product` has an optional `product_variant_id`. Simple products store SKU/GTIN on Product. Multi-variant products use Variant table.
2. Every `Product` always has at least one `ProductVariant`. SKU/GTIN/MPN are always on Variant.

### Options considered

- **A: Optional Variant.** Product carries identifiers directly. Variant table created only when multiple variants exist.
- **B: Mandatory Variant.** Product always has a default Variant. Identifiers always on Variant.

### Chosen option: B — Mandatory Variant

### Reasons

1. **Uniform data model:** Future modules (billing, inventory, Fortnox, QR, DPP, Excel import) always reference SKU/GTIN from a single table. No conditional logic for "does this product have variants or not?".
2. **No dual path for identifiers:** Option A means SKU could be on `products.sku` or `product_variants.sku`. Every query, import, export, and validation must handle both paths. Option B has exactly one path.
3. **Variant-level attributes:** Even simple products may need variant-level attributes later (e.g., a "Default" packaging unit). Option A requires data migration when adding a variant.
4. **Default Variant invariant:** The default Variant serves as the primary representation for listings, API responses, and external system mapping. This replaces the "simple product" concept.
5. **Industry standard:** Magento, Shopware, Akeneo, and Salesforce Commerce Cloud all use the "product always has variants" model. It has proven scalable.

### Consequences

- Creating a Product requires an atomic transaction that creates both Product and its default Variant.
- The default Variant's `name` defaults to `"Default"` when no attributes are assigned.
- UI can hide variant selection for single-variant products.
- `default_variant_id` FK on Products simplifies "get the representative SKU/GTIN" queries.

### Rejected alternatives

**Option A (Optional Variant)** was rejected because:
- It creates two parallel data paths for identifiers.
- Future expansion (adding variants to a simple product) requires risky data migration.
- Every identifier-bearing query needs conditional logic or UNION.

---

## Decision 2: SKU Belongs to Variant

### Context

SKU (Stock Keeping Unit) identifies a sellable unit. NordiPass uses Product-Variant model, so the sellable unit is always a Variant.

### Options considered

- **A: SKU on Product.** Simple, but breaks when a product has variants.
- **B: SKU on Variant.** Identifier follows the sellable unit.

### Chosen option: B — SKU on Variant

### Reasons

1. The Variant is the sellable/identifiable unit. SKU describes "which configuration."
2. External systems (Fortnox, billing, warehouse) reference SKU. They need the specific variant, not the product group.
3. Consistent with Product→Variant model decision.
4. A Company-unique SKU on Variant prevents catalog duplicates.

### Consequences

- `UNIQUE(company_id, sku_normalized)` on `product_variants`.
- Product listings show the default Variant's SKU.
- SKU is optional — products can be created before SKU assignment.
- Archived SKUs cannot be reused (normalized column remains occupied).

### Rejected alternatives

**Option A (SKU on Product)** was rejected because:
- When a product gains variants, the SKU must be migrated from Product to a specific Variant.
- For multi-variant products, the Product concept has no inherent SKU — it's a group.

---

## Decision 3: GTIN Belongs to Variant

### Context

GTIN (EAN/UPC) identifies a specific product configuration at the point of sale. Different colors/sizes of the same base product have different GTINs.

### Options considered

- **A: GTIN on Product.** Assumes GTIN identifies the product family.
- **B: GTIN on Variant.** GTIN identifies the specific configuration.

### Chosen option: B — GTIN on Variant

### Reasons

1. GS1 standards: Each distinct product configuration (color, size, packaging) gets a unique GTIN. This maps exactly to Variant.
2. Different variants of the same product MUST have different GTINs in practice (retail, customs, logistics).
3. Scanning a barcode at a warehouse or checkout identifies a specific variant, not a product group.

### Consequences

- `gtin` column on `product_variants` (varchar 14, digit-only).
- `UNIQUE(company_id, gtin)` — MySQL allows multiple NULL values in UNIQUE indexes, so non-null GTINs are unique per Company while NULL GTINs do not conflict. No partial index needed.
- GTIN is optional (null allowed).
- Company-level uniqueness only. Same GTIN at different companies is allowed (resellers).
- Check digit validated at application level on save.
- Accepted lengths: 8 (GTIN-8), 12 (UPC/GTIN-12), 13 (EAN-13/GTIN-13), 14 (GTIN-14).

### Rejected alternatives

**Option A (GTIN on Product)** was rejected because it contradicts GS1 standards and real-world usage where each variant has a distinct barcode.

---

## Decision 4: Category Relationship — Many-to-Many with Primary Category FK on Product

### Context

Products can logically belong to multiple categories. Navigation, breadcrumbs, and SEO need a single primary classification.

### Options considered

- **A: Single category (products.category_id FK).** Simple hierarchy, one-to-many.
- **B: Many-to-many via pivot with `is_primary` boolean.** Primary designation on pivot row.
- **C: Many-to-many with `products.primary_category_id` FK.** Primary stored as direct FK on Product. Secondary categories in pivot.

### Chosen option: C — `products.primary_category_id` FK + `category_product` pivot

### Reasons

1. **MySQL-compatible:** No partial unique index or generated-column strategy needed for exactly-one-primary enforcement. The FK column is inherently single-value.
2. **Simple queries:** "What is the primary category?" = single column read. No `WHERE is_primary = 1` filter needed.
3. **Deterministic:** Exactly one primary per Product by design — an FK column can only hold one value.
4. **Pivot stays clean:** `category_product` pivot has no boolean flags. It stores pure many-to-many relationships.
5. **Explicit archival behavior:** When primary Category is archived, `products.primary_category_id` is set to NULL. The Category can remain in the pivot if desired.

### Consequences

- `products.primary_category_id` nullable FK → categories.
- Active Products MUST have non-null `primary_category_id` (hard gate).
- `category_product` pivot has columns: `category_id`, `product_id`, `created_at`.
- The primary Category SHOULD also exist in `category_product` (application-level consistency sync).
- Archiving primary Category sets the FK to NULL on affected Products.

### Rejected alternatives

**Option A (Single category)** was rejected as too restrictive.

**Option B (Pivot `is_primary`)** was rejected because:
- Looks clean but MySQL has no `WHERE is_primary = 1` partial unique index support.
- Generated-column strategies (`UNIQUE (product_id, CASE WHEN is_primary = 1 THEN 1 END)`) do not work in MySQL — functional indexes differ from PostgreSQL.
- Application-level enforcement of exactly-one-primary on a pivot is fragile under race conditions.

---

## Decision 5: Attribute Storage — Two Entity-Specific Tables

### Context

Attribute values must reference Products and Variants. A polymorphic approach (entity_type + entity_id) avoids duplicate schema but loses referential integrity.

### Options considered

- **A: Single polymorphic table.** `entity_type` + `entity_id`, no real FKs.
- **B: Two entity-specific tables.** `product_attribute_values` and `variant_attribute_values` with real FKs.

### Chosen option: B — Two entity-specific tables

### Reasons

1. **Real foreign keys:** `product_id` FK → products, `product_variant_id` FK → product_variants. Cascade/restrict works natively.
2. **Tenant safety:** `company_id` FK → companies. Database can verify company integrity.
3. **No morph strings:** No `entity_type = 'product'` strings that can be mistyped or corrupted.
4. **Simple queries:** `WHERE product_id = ?` is a direct FK lookup. No polymorphic JOIN complexity.
5. **Magento pattern:** Similar to Magento 2's `catalog_product_entity_*` tables — proven at scale.

### Consequences

- Two tables with identical typed-value columns (value_text, value_integer, etc.).
- Each table has: `company_id`, `attribute_definition_id`, entity FK, typed columns.
- `UNIQUE(attribute_definition_id, product_id)` on product table; same pattern on variant table.
- Both tables share the same typed-column design (Decision 6).

### Rejected alternatives

**Option A (Polymorphic)** was rejected because:
- No FK to products or variants — entity_type is an unvalidated string.
- Cascade/restrict behavior requires application code.
- Tenant isolation is harder without real FKs.

---

## Decision 6: Attribute Typed Columns — Same Pattern on Both Tables

### Context

Attributes have different data types. Storage must support type-safe queries and filtering.

### Options considered

- **A: JSON column.** Single flexible column.
- **B: Single varchar column.** Classical EAV.
- **C: Typed nullable columns.** `value_text`, `value_integer`, `value_decimal`, `value_boolean`, `value_date`, `value_option_id`.

### Chosen option: C — Typed nullable columns

### Reasons

1. **Database-enforced types:** `value_integer` only accepts integers. No casting on read.
2. **One table = one query per entity type:** All attributes for a Product come from `product_attribute_values` with one JOIN.
3. **Filterable:** `WHERE value_integer > 100` is a native comparison.
4. **Industry precedent:** Magento 2 uses typed value columns on entity-specific tables. Proven pattern.

### Consequences

- Each type maps to one column: text → value_text, integer → value_integer, decimal → value_decimal, boolean → value_boolean, date → value_date, select → value_option_id.
- Application validates exactly one value column is populated per row.
- `value_option_id` is a FK to `attribute_options` for real referential integrity on select values.

### Rejected alternatives

**Option A (JSON)** — no type enforcement, no efficient indexing.
**Option B (Varchar EAV)** — no type safety, casting overhead.

---

## Decision 7: Multiselect Storage — Pivot Table

### Context

Multiselect attributes allow multiple option values per entity. Storage must maintain referential integrity.

### Options considered

- **A: JSON array in `value_options` column.**
- **B: `attribute_value_options` pivot table.**

### Chosen option: B — `attribute_value_options` pivot table

### Reasons

1. **Real FKs:** `attribute_option_id` FK → attribute_options ensures every selected option actually exists.
2. **Queryable:** `SELECT * FROM attribute_value_options WHERE attribute_option_id = ?` returns all entities using that option. JSON `JSON_CONTAINS` is slower and not indexable.
3. **Integrity:** When an option is archived, FKs prevent dangling references. With JSON, orphan references require application-level cleanup.
4. **No partial index needed:** Pivot UNIQUE is simply `UNIQUE(attribute_value_id, attribute_option_id)`.

### Consequences

- `attribute_value_options` table with columns: `product_attribute_value_id` (nullable FK), `variant_attribute_value_id` (nullable FK), `attribute_option_id` (FK).
- CHECK constraint ensures exactly one of the two value FKs is non-null.
- When a multiselect value is updated, old pivot rows are deleted and new ones inserted in the same transaction.

### Rejected alternatives

**Option A (JSON)** was rejected because:
- No FK integrity — a deleted option leaves dangling references.
- JSON arrays cannot be efficiently joined for "find all products with option X."
- `JSON_CONTAINS` queries are slower than indexed FK joins.

---

## Decision 8: Deletion Strategy — Archive (Soft-Delete) for User Operations

### Context

Users need to remove products/categories/variants from the active catalog but preserve history.

### Options considered

- **A: Hard delete only.**
- **B: Soft delete (deleted_at).**
- **C: Archive status + soft delete.**

### Chosen option: C — Archive status + soft delete

### Reasons

1. **Archive is the user-facing operation.** Setting `status = 'archived'` means "remove from active catalog, preserve."
2. **Soft delete is the administrative safety net.** Even after archiving, data is not physically removed.
3. **Consistent with R0:** `CompanyStatus` uses Active/Suspended/Archived. Company model uses SoftDeletes.
4. **Two layers of protection:** Archive = user action (reversible, removes from listings). Soft delete = admin action (reversible within retention).
5. **Identifier preservation:** Both archive and soft-delete keep the identifier column populated, so SKU/slug/GTIN values remain occupied and cannot be reused.

### Consequences

- Product, Variant, Category, and AttributeDefinition models have both `status` + `deleted_at`.
- `deleted_at` set only by administrative operations, not user-facing controllers.
- Archived SKU stays in `sku_normalized` column → blocks reuse via UNIQUE constraint.

### Rejected alternatives

**Option A (Hard delete)** — loses audit trail, external references, cannot recover.
**Option B (Soft delete only)** — doesn't distinguish "user archived" from "admin deleted."

---

## Decision 9: Tenant Ownership — Denormalized company_id on All Tables

### Context

R0 denormalizes `company_id` on `company_invitations` and `personal_access_tokens`. Same pattern for catalog.

### Options considered

- **A: No denormalization.** Only Product carries company_id.
- **B: Denormalized company_id on all catalog tables.**

### Chosen option: B — Denormalized company_id

### Reasons

1. **Query safety:** Every query includes `WHERE company_id = ?`. Missing join cannot leak data.
2. **Index efficiency:** `UNIQUE(company_id, sku_normalized)` works without joining products.
3. **Consistency with R0.**
4. **Anti-corruption:** Mismatched `product_id`/`company_id` is detectable.

### Consequences

- All catalog tables have `company_id` FK → companies.
- Actions set `company_id` from parent entity.
- Application enforces `variant.company_id === product.company_id`.

### Rejected alternatives

**Option A** — unique constraints require company context; every tenant query needs JOIN.

---

## Decision 10: Identifiers — UUID Route Binding

### Context

R0 uses UUIDs for public route binding (Company, CompanyInvitation) and HasUuid trait.

### Options considered

- **A: Numeric IDs.** Sequential, predictable.
- **B: UUIDs.** Non-sequential, opaque.
- **C: ULIDs.** Time-sortable UUID alternative.

### Chosen option: B — UUIDs (R0 convention)

### Reasons

1. Consistency with R0.
2. No information leakage (sequential IDs reveal catalog size).
3. Safe for future public URLs.
4. Existing `HasUuid` trait reuses.

### Consequences

- All catalog models use HasUuid trait.
- Internal auto-increment IDs never exposed.
- Route binding: `{product:uuid}`, `{variant:uuid}`, etc.

### Rejected alternatives

**Option A** — inconsistent with R0. **Option C** — no ULID infrastructure in R0.

---

## Decision 11: Categories — Adjacency List with Depth Column

### Context

Categories form a hierarchy. Common SQL patterns: adjacency list (parent_id), nested set (left/right), materialized path.

### Options considered

- **A: Adjacency list** (parent_id + depth).
- **B: Nested set** (lft/rgt).
- **C: Materialized path** (path string).

### Chosen option: A — Adjacency list with depth column

### Reasons

1. **Simplicity:** Moving a Category is a single UPDATE. Nested set requires rebalancing entire subtrees.
2. **Depth column** enables cheap depth validation without recursive CTEs.
3. **Expected scale:** <500 categories per Company. Adjacency list scales well.
4. **Laravel-native:** `hasMany children` / `belongsTo parent` relations.

### Consequences

- Self-referencing FK `parent_id → categories.id`.
- `depth` updated on move.
- Cycle detection: application-level validation before save in transaction.
- Tree queries use recursive CTE or eager-loaded children.

### Rejected alternatives

**Option B (Nested set)** — moving nodes is complex and error-prone. Overkill for R1 volumes.
**Option C (Materialized path)** — fragile string manipulation, no native subtree index.

---

## Decision 12: Attributes Without Inheritance

### Context

Some PIM systems use attribute inheritance: Variant inherits Product-level values unless overridden.

### Options considered

- **A: Inheritance with override.**
- **B: Independent values** — Product and Variant each have their own values.

### Chosen option: B — Independent values (no inheritance)

### Reasons

1. **Predictability:** "What is Color for this Variant?" always has one answer.
2. **No fallback chains:** No "where does this value come from?" ambiguity.
3. **Scope `both` = two rows:** Same definition, independent values at Product and Variant level.

### Consequences

- Values always explicitly set per entity.
- UI can suggest copying Product values to Variant (explicit operation).
- No inheritance resolution in API.

### Rejected alternatives

**Option A** — ambiguity in API, complex "effective value" computation.

---

## Decision 13: No Separate `inactive` Product Status

### Context

Some catalogs distinguish `inactive` (temporarily hidden) from `draft` and `archived`.

### Options considered

- **A: Four statuses** — draft, active, inactive, archived.
- **B: Three statuses** — draft, active, archived.

### Chosen option: B — Three statuses

### Reasons

1. `inactive` and `draft` overlap semantically. `published_at` captures "was it ever published?"
2. Simpler state machine: 3×5 vs 4×10 transitions.
3. Temporary deactivation = archive + restore.

### Consequences

- Product status: draft, active, archived.
- `published_at` preserved across cycles to track first-publication.

### Rejected alternatives

**Option A** — premature complexity, doubling transition testing.

---

## Decision 14: Media Ownership — Single product_media Table

### Context

Images belong to Product as a whole or to a specific Variant.

### Options considered

- **A: Polymorphic morphTo.**
- **B: Separate tables** (product_media + variant_media).
- **C: Single product_media table** with optional variant_id.

### Chosen option: C — Single table with optional variant_id

### Reasons

1. **One query for all images:** `WHERE product_id = ?` returns both levels.
2. **Variant fallback is query-driven:** `WHERE product_id = ? AND (variant_id = ? OR variant_id IS NULL)`.
3. **Consistent with Product aggregate:** Images always loaded through Product.
4. **Primary image via FK columns** on owning entities — no `is_primary` boolean needed (Decision 17).

### Consequences

- `product_id` always set. `variant_id` null = product-level.
- Company_id denormalized.
- No partial unique index needed — primary tracked via FK columns.

### Rejected alternatives

**Option A** — no real FKs, fragile morph strings. **Option B** — UNION for all images query.

---

## Decision 15: Publication Readiness — Hard Gates + Soft Gates

### Context

What conditions must a Product satisfy before activation?

### Options considered

- **A: All hard.** Everything must pass.
- **B: All soft.** Warnings only.
- **C: Mixed** — hard gates block, soft gates warn.

### Chosen option: C — Mixed hard + soft gates

### Reasons

1. **Hard gates protect data integrity** (name, slug, default Variant, primary Category).
2. **Soft gates support workflow** (image, SKU can be added after activation).
3. **Progressive catalog building:** Companies start lean, enrich later.

### Hard gates (8):
1. name non-empty; 2. slug present/unique; 3. Variant exists; 4. default Variant assigned; 5. default Variant not archived; 6. primary_category_id non-null; 7. required product attributes filled; 8. required variant attributes filled on default Variant.

### Soft gates (2):
9. SKU on default Variant present; 10. primary_media_id non-null.

### Consequences

- Activation Action validates hard gates; failed gates returned as validation error.
- Soft gates surfaced in UI as warnings.

---

## Decision 16: Default Variant FK Storage — Nullable FK Set in Transaction

### Context

Every Product must have exactly one default Variant. MySQL has no deferrable FKs, so the Product and Variant cannot both have NOT NULL references to each other at creation time.

### Options considered

- **A: NOT NULL `default_variant_id`.** Requires Variant insertion before Product — Variant needs product_id first.
- **B: Nullable `default_variant_id`.** Set to NULL initially, updated in same transaction.
- **C: `product_variants.is_default` boolean only.** No FK from Product to Variant.

### Chosen option: B — Nullable FK set in transaction

### Reasons

1. **Chicken-and-egg resolved:** Product inserts with `default_variant_id = NULL` → Variant inserts → Product UPDATE sets `default_variant_id`. All in one transaction.
2. **FK integrity maintained:** After transaction commits, FK points to a real Variant.
3. **Application-level invariant:** No code ever reads `default_variant_id` between the INSERT and UPDATE (same transaction). External consumers never observe NULL.
4. **Cleaner than Option C:** Having an FK provides referential integrity. Option C relies purely on application-level consistency.

### Consequences

- `products.default_variant_id` nullable FK → product_variants.
- `product_variants.is_default` boolean for fast queries.
- Both are toggled in the same transaction on default change.
- Integrity check CLI command detects any NULL `default_variant_id` as corruption.

### Rejected alternatives

**Option A (NOT NULL)** — impossible without deferrable FKs (MySQL limitation).
**Option C (no FK)** — loses referential integrity; solely application-enforced.

---

## Decision 17: Primary Image Storage — FK Columns on Owning Entity

### Context

Each Product and each Variant can have one primary image. A boolean `is_primary` flag on `product_media` needs exactly-one-per-scope enforcement that MySQL partial unique indexes cannot support.

### Options considered

- **A: `is_primary` boolean on product_media** with application-level enforcement.
- **B: FK columns on owning entities:** `products.primary_media_id` + `product_variants.primary_media_id`.
- **C: Generated-column strategy** with `UNIQUE INDEX`.

### Chosen option: B — FK columns on owning entities

### Reasons

1. **MySQL-compatible:** A single FK column is inherently single-value. No partial unique index or generated columns needed.
2. **Direct:** "What is the primary image?" = read the FK column. No `WHERE is_primary = 1` filter.
3. **Referential integrity:** FK ensures the referenced media row actually exists and cascades appropriately.
4. **No `is_primary` column needed on product_media.** Redundant — the FK on the owner defines primary.

### Consequences

- `products.primary_media_id` nullable FK → product_media.
- `product_variants.primary_media_id` nullable FK → product_media.
- Action validates: target media row has matching `product_id` and (for variants) matching `variant_id`.
- When primary media is deleted, Action sets FK to NULL. Optionally promotes next image by sort_order.
- No `is_primary` column on `product_media`.

### Rejected alternatives

**Option A** — application-level enforcement of exactly-one-primary is fragile (race conditions).
**Option C** — MySQL generated-column unique indexes have incompatible syntax with `CASE WHEN` expressions.

---

## Decision 18: Category Archive Policy — Explicit Operations Only

### Context

Archiving a Category with children or products. Options: automatic side effects or explicit operator decisions.

### Options considered

- **A: Automatic** — reparent children to root, clear primary references silently.
- **B: Explicit** — reject archive if blocked; operator must resolve dependencies first.

### Chosen option: B — Explicit operations only

### Reasons

1. **No surprising side effects.** Archiving a parent Category does not silently restructure the tree.
2. **Operator visibility.** The operator sees exactly which active children and products are affected before archive.
3. **Safety.** Implicitly moving children to root can silently break catalog structure and SEO.

### Consequences

- Archive Category is REJECTED if: it has active children, or it is the primary Category for any active Product.
- Validation error lists the blocking children/products.
- Operator must first: move/archive children, or reassign primary Categories on affected Products.
- Then archive the Category.
- Restoring a Category does NOT restore the parent relationship — operator explicitly moves.

### Rejected alternatives

**Option A** — surprising behavior, silently destructive.

---

## Decision 19: API Token Abilities + Membership Authorization

### Context

Should API token ability alone grant access, or must the membership role also permit the operation?

### Options considered

- **A: Token ability only.**
- **B: Token ability + membership authorization.**

### Chosen option: B — Both must pass

### Reasons

1. **Consistent with R0.** R0 API tokens require both ability + membership.
2. **Defense in depth.** Token compromise limits damage to the user's role.
3. **Role changes reflect immediately.** Demoted user's token loses write access.

### Consequences

- API middleware chain: Token → Ability → Company → Membership → Policy.
- Editor with `catalog.write` token can create/update but cannot publish/archive.
- Token abilities intentionally less granular than CompanyPermissions.

### Rejected alternatives

**Option A** — bypasses R0 authorization infrastructure entirely.

---

## R1.2 Architecture Addendum: Tenant-Safe Pointers and Category Self-Parent Protection

### Original decision

R1.1 stored `products.primary_category_id`, `products.default_variant_id`,
`products.primary_media_id`, and `product_variants.primary_media_id` as nullable
foreign-key pointers. Matching the pointer target to the owning Company/Product/Variant
was originally described as Action-layer validation. R1.1 also expected a database
CHECK to reject `categories.parent_id = categories.id`.

### Technical conflict

R1.2 requires the database to reject cross-tenant and wrong-owner pointer targets.
Single-column foreign keys prove only that the target ID exists and therefore do not
meet that requirement. MySQL 8 can express the owner checks with composite foreign
keys and supporting unique keys.

MySQL 8.0.46 rejects a CHECK that compares `parent_id` with the auto-increment `id`
(`SQLSTATE 3818: Check constraint cannot refer to an auto-increment column`). This
prevents the originally requested CHECK implementation even though the predicate is
otherwise valid SQL.

### Final MySQL-compatible decision

- Primary Category uses `(company_id, primary_category_id) → categories(company_id, id)`.
- Default Variant uses `(company_id, product.id, default_variant_id) → product_variants(company_id, product_id, id)`.
- Product primary media uses `(company_id, product.id, primary_media_id) → product_media(company_id, product_id, id)`.
- Variant primary media uses `(company_id, product_id, variant.id, primary_media_id) → product_media(company_id, product_id, product_variant_id, id)`.
- Multiselect pivots include `attribute_definition_id` in both the value and Option composite foreign keys.
- Two narrowly scoped MySQL triggers reject direct Category self-parenting on INSERT and UPDATE. They do not attempt arbitrary cycle detection.

### Consequences

- Cross-company and wrong-owner pointer assignments are rejected by MySQL without relying on Actions.
- Pointer foreign keys use `RESTRICT`; Actions must clear or replace pointers before a referenced row is hard-deleted. Normal catalog removal remains soft-delete/status based.
- A Product primary media pointer may still reference variant-level media of the same Product; requiring `product_variant_id IS NULL` remains an Action invariant because conditional foreign keys are unavailable.
- Deep Category cycles still require transactional recursive validation in R1.4.
- Trigger names are stable and rollback explicitly drops them before dropping `categories`.
