# NordiPass R1.3 — Catalog Foundation

**Stage:** R1.3  
**Date:** 2026-07-14  
**Canonical model namespace:** `App\Models\Catalog`

## Inventory

The foundation exposes these Eloquent models:

- aggregate models: `Product`, `ProductVariant`, `Category`, `ProductMedia`;
- attribute models: `AttributeDefinition`, `AttributeOption`, `ProductAttributeValue`, `VariantAttributeValue`;
- explicit assignment models: `CategoryProduct`, `ProductAttributeValueOption`, `VariantAttributeValueOption`.

UUID route binding is enabled only for tables that actually contain a `uuid` column: Product, ProductVariant, Category, AttributeDefinition, and ProductMedia. Product, ProductVariant, Category, and ProductMedia use `SoftDeletes`, matching the R1.2 schema. No catalog model has a global Company scope.

Every tenant-owned model instead exposes `forCompany(Company|string $company)`. A persisted Company, numeric ID string, or Company UUID may be supplied explicitly. The scope never reads `CurrentCompany` implicitly.

## Relationship map

| Owner | Relationships |
|---|---|
| Company | categories, products, productVariants, attributeDefinitions, attributeOptions, productMedia |
| Category | company, parent, children, products, createdBy, updatedBy |
| Product | company, variants, defaultVariant, categories, primaryCategory, attributeValues, media, productMedia (product-level only), primaryMedia, createdBy, updatedBy |
| ProductVariant | company, product, attributeValues, media, primaryMedia, createdBy, updatedBy |
| AttributeDefinition | company, options, productValues, variantValues, createdBy, updatedBy |
| AttributeOption | company, definition, productSelectValues, variantSelectValues, productMultiselectAssignments, variantMultiselectAssignments |
| Product/VariantAttributeValue | company, owning entity, definition, selectedOption, multiselectAssignments, selectedOptions |
| ProductMedia | company, product, variant, uploadedBy |

Relations use globally unique internal primary keys where Eloquent cannot express a composite relationship. R1.2 composite foreign keys remain the authoritative same-Company and same-owner integrity layer.

## Cast and state map

- Product status: `draft`, `active`, `archived`; `published_at` is an immutable datetime.
- Variant status: `draft`, `active`, `archived`.
- Category, AttributeDefinition, and AttributeOption status: `active`, `archived`.
- Attribute type: `text`, `integer`, `decimal`, `boolean`, `date`, `select`, `multiselect`.
- Attribute scope: `product`, `variant`, `both`.
- The actual R1.2 AttributeDefinition columns are used: `type`, `required`, `filterable`, and `searchable`.
- Attribute validation rules cast to array; booleans and sort order use strict scalar casts.
- Typed decimal values cast with the schema precision `decimal:4`; dates use `immutable_date`.

`products.default_variant_id` is the sole application source of truth for the default Variant. The legacy physical `product_variants.is_default` column is not fillable, cast, queried, or mutated by R1.3. `ProductVariant::isDefaultFor()` compares only the Product pointer.

## Mass-assignment boundary

Tenant keys, owner foreign keys, default/primary pointers, actor fields, normalized identifiers, publication timestamps, and deleted timestamps are excluded from normal mass assignment. Trusted Actions assign them explicitly with values derived from persisted Company, Product, Variant, and User objects. Technical normalized fields and media storage/checksum fields are hidden from accidental serialization.

No model event assigns a Company, creates a Variant, writes audit data, changes lifecycle state, or deletes a physical media file.

## Identifier normalization

`CatalogIdentifierNormalizer` provides separate methods for each identifier family:

| Identifier | Rule |
|---|---|
| Product/Category slug | trim, Unicode transliteration through `Str::slug`, lowercase ASCII, one hyphen separator, maximum 255 |
| SKU normalized mirror | trim, validate identifier characters, remove all whitespace, uppercase, maximum 100; display SKU retains case and internal spacing |
| GTIN | trim, digits only, exact length 8/12/13/14, valid GS1 check digit; invalid text or punctuation throws |
| MPN | trim only, preserve case and internal whitespace, empty becomes null, maximum 100 |
| Attribute/Option code | trim, transliterate, lowercase, underscore separator, maximum 100 |

Normalization is called explicitly before persistence by catalog Actions. It does not query the database, generate a unique suffix, or silently strip invalid GTIN characters.

## Company permission matrix

Existing R0 permission strings are unchanged. R1.3 adds exactly eight catalog permissions and no `catalog.delete` permission.

| Permission | Owner | Admin | Editor | Viewer |
|---|---:|---:|---:|---:|
| catalog.view | allow | allow | allow | allow |
| catalog.create | allow | allow | allow | deny |
| catalog.update | allow | allow | allow | deny |
| catalog.archive | allow | allow | deny | deny |
| catalog.publish | allow | allow | deny | deny |
| catalog.manage_categories | allow | allow | deny | deny |
| catalog.manage_attributes | allow | allow | deny | deny |
| catalog.manage_media | allow | allow | allow | deny |

Named Gates use the existing `CompanyPermissionGate`, `CompanyAuthorizer`, fresh membership lookup, `CurrentCompany`, active User checks, active Company checks, and `CompanyPermissionMatrix`.

## Policy map

All policies are explicitly registered in `AuthorizationServiceProvider`. Model policies re-read the target row and its Company before authorization, so a stale or locally modified model cannot change tenant ownership for the check.

| Policy | Methods | Permission |
|---|---|---|
| ProductPolicy | viewAny, view, create, update, archive, publish, manageMedia | catalog.view/create/update/archive/publish/manage_media |
| ProductVariantPolicy | view, create, update, archive, setDefault, manageMedia | catalog.view/create/update/archive/manage_media |
| CategoryPolicy | viewAny, view, create, update, move, archive, restore | catalog.view / catalog.manage_categories |
| AttributeDefinitionPolicy | viewAny, view, create, update, archive | catalog.view / catalog.manage_attributes |
| AttributeOptionPolicy | view, create, update, archive | catalog.view / catalog.manage_attributes |
| ProductMediaPolicy | view, create, update, delete | catalog.view / catalog.manage_media |

All model operations require the persisted model to belong to the active CurrentCompany. `ProductVariantPolicy::setDefault` additionally verifies the persisted Product/Variant Company and ownership link.

## Foundational Actions

| Action | Transaction | Row lock | Audit | Invariant |
|---|---|---|---|---|
| CreateProductWithDefaultVariantAction | yes | not required for new rows | catalog.product.created, catalog.variant.created | Product, its first Variant, default pointer, actors, and audit commit or roll back together |
| SetDefaultProductVariantAction | yes | Product then target Variant via `lockForUpdate` | catalog.variant.default_changed | fresh pointer is read under lock; wrong Product/Company and archived Variant are rejected; repeat selection is idempotent |

Creation always starts Product and Variant in `draft`, assigns `company_id` from the trusted Company, ignores payload owner/system keys, and returns the Product with `defaultVariant` loaded. MySQL duplicate identifier errors are mapped from driver code 1062 to `CatalogIdentifierConflict` without exposing SQL details.

Default-change audit metadata contains only Product UUID and old/new Variant UUIDs. Product-create metadata contains Product UUID/name/status and default Variant UUID. Audit writes occur inside the mutation transaction and continue through the existing sensitive-data sanitizer.

## Audit event inventory

R1.3 reserves the agreed events for category create/update/move/archive/restore; product create/update/activate/archive/restore; variant create/update/default-change/archive/restore; attribute create/update/archive; and media upload/update/delete. Only the two foundational Actions emit events in this stage.

## Guarantee boundaries

### Database guarantees (R1.2)

- Company-scoped unique normalized Product/Category slug, Variant SKU, Variant GTIN, and attribute/option codes;
- same-Company composite foreign keys across catalog ownership paths;
- default Variant, primary Category, and primary media pointer ownership;
- media Product/Variant ownership;
- typed attribute one-scalar-value limit and multiselect Definition consistency;
- status/type/scope/checksum/GTIN shape checks.

### Application guarantees (R1.3)

- explicit tenant query scopes with no global scope;
- mass-assignment protection for tenant and integrity fields;
- active User, active Company, fresh membership, CurrentCompany, role, and same-tenant policy checks;
- Product and default Variant are created atomically in draft state;
- identifiers are normalized and GTIN check digits validated before insert;
- Product default pointer is changed from a fresh locked row and is idempotent;
- mutation and audit either commit together or roll back together.

### Deferred guarantees

- Product/category update, archive, restore, and publication workflows;
- category move, recursive cycle detection beyond direct self-parent, and archive blockers;
- attribute Definition/value mutation rules, required-attribute readiness, and multiselect replacement operations;
- media upload/storage/deletion and primary-media lifecycle;
- search/filter query builders, HTTP/API resources, token abilities, controllers, routes, Jobs, and UI.

## Test profiles

- The standard `phpunit.xml` profile and the focused `phpunit.mysql.xml` profile both use the dedicated MySQL database `nordipass_testing`.
- The test bootstrap rejects SQLite, every non-MySQL connection, and any MySQL database name without the `_testing` suffix.
- Catalog schema, model, relationship, policy, and Action tests run without database-driver skips in the standard suite.
