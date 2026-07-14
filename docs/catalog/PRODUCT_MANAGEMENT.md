# NordiPass R1.5 — Product Management

**Stage:** R1.5  
**Date:** 2026-07-14  
**Database:** MySQL 8 for local development, CI, production, and every database-backed test

## Scope

R1.5 adds tenant-safe Blade management for the Product aggregate: a paginated list, create, show, and edit flows, assignment of a primary and additional Categories, one mandatory default Variant, safe audit events, and deterministic local demo data. A draft Product may be saved without any Category.

The stage deliberately excludes Product lifecycle transitions, archive/delete controls, Variant CRUD, attributes, documents, media upload, prices, inventory, API endpoints, import/export, and R2+ behavior.

## Routes and screens

All routes use the existing authenticated, verified, resolved/selected Company, active membership, and active Company middleware stack.

| Method | Route name | Screen or operation |
|---|---|---|
| GET | `catalog.products.index` | Company-scoped list, filters, 25-row pagination |
| GET | `catalog.products.create` | Product and Category assignment form |
| POST | `catalog.products.store` | Atomic Product aggregate creation |
| GET | `catalog.products.show` | Product, Categories, record metadata, and default Variant |
| GET | `catalog.products.edit` | Managed Product fields and Category assignment |
| PATCH | `catalog.products.update` | Atomic managed-field and Category update |

The index supports status and primary-Category filters. It eager-loads `primaryCategory` and `defaultVariant` and uses `withCount('categories')`. Create/edit selectors contain only active Categories from the current Company. The default Variant is displayed read-only. Viewer pages do not render create/edit controls, while backend authorization remains authoritative.

## Permissions and tenant resolution

Owner, Admin, and Editor may create and update Products through `catalog.create` and `catalog.update`. Viewer has `catalog.view` and may use list/show only.

Controllers resolve Products and Categories through `forCompany($currentCompany)`. A foreign UUID is concealed as 404. Form Requests enforce policies, and Actions independently re-authorize the active User, fresh membership, active Company, and CurrentCompany context. Request values cannot select `company_id`, lifecycle state, pointers, actor IDs, or deleted state.

## Managed Product fields

R1.5 accepts only:

- `name` — required, maximum 255 characters;
- `slug` — optional on create and required on update, maximum 255;
- `short_description` — optional, maximum 500;
- `description` — optional, maximum 10,000;
- `brand` and `manufacturer` — optional, maximum 255 each;
- one optional primary Category UUID and additional Category UUIDs.

`CatalogIdentifierNormalizer` trims and normalizes the Product slug. A blank create slug is generated from the name. The race-safe database guarantee is `UNIQUE(company_id, slug_normalized)`, and MySQL duplicate error 1062 is mapped to a safe Product domain error. The same normalized slug remains valid in another Company.

The application never accepts changes to `company_id`, `status`, `published_at`, `default_variant_id`, `primary_category_id`, `primary_media_id`, `created_by`, `updated_by`, or soft-delete fields from Product payloads.

## Default Variant behavior

Every Product is created with exactly one draft Variant named `Default`. It belongs to the same Company and Product, has sort order zero, null SKU/GTIN/MPN and media pointer for normal UI creation, and is installed as `products.default_variant_id` inside the same transaction. R1.5 does not expose Variant mutation routes or form inputs.

`ProductAggregateCreator` contains this low-level aggregate invariant. Production Actions own validation, authorization, transaction, and audit. The local demo seeder may reuse the creator with trusted explicit values.

## Create transaction

`CreateProductAction` performs one transaction:

1. repeat authorization against trusted Company context;
2. normalize and bound managed fields;
3. insert the draft Product and default Variant and set the pointer;
4. validate and synchronize Categories;
5. write one `catalog.product.created` audit event;
6. return the aggregate with Variant and Categories loaded.

Any Category, database, or audit failure rolls back Product, Variant, pivot rows, pointer changes, and audit together.

## Update transaction

`UpdateProductAction` locks and re-reads the tenant Product, normalizes managed fields, checks the Company-scoped slug, saves only changed fields, synchronizes Categories, and writes one `catalog.product.updated` event. Product fields, pointers, pivot changes, actor attribution, and audit share one transaction. An exact no-op writes neither Product nor audit.

System-managed fields remain unchanged even if malicious payload keys are present. Any duplicate slug, unavailable Category, database failure, or audit failure restores the complete previous aggregate.

## Category synchronization

`ProductCategoryService` validates every requested UUID against the trusted Company before mutation, locks selected Category and current pivot rows, rejects archived Categories, safely deduplicates UUIDs, and limits the aggregate to 20 distinct Categories including primary.

The primary Category invariant is:

```text
products.primary_category_id IS NULL
or
the same Category exists in category_product for that Product and Company
```

Synchronization inserts/removes explicit `category_product` rows with trusted `company_id`, changes the primary pointer in the same transaction, and removes obsolete assignments. A Product may have no primary or additional Category while it remains draft.

## Audit events

R1.5 emits:

- `catalog.product.created`: Product UUID, safe name/slug/status, default Variant UUID, primary Category UUID, and Category count;
- `catalog.product.updated`: Product UUID, names of changed fields, old/new primary Category UUID, and old/new Category counts.

Descriptions, arbitrary request payloads, raw database errors, and sensitive values are not recorded. Demo seeding intentionally creates no user audit history, so repeated seeding cannot create duplicate audit events.

## Demo seeder

`CatalogDemoSeeder` is an idempotent, transactional demonstration seeder. It refuses environments other than `local` and `testing`, requires the dedicated `NordiPass Demo AB` Company and `owner@nordipass.local` membership from `LocalDevelopmentSeeder`, and never chooses an arbitrary Company. `DatabaseSeeder` calls it only inside an explicit `local` branch; the normal `testing` and production seed flows do not install demo catalog records.

The seeder uses trusted explicit aggregate creation rather than HTTP-oriented Actions. It assigns Company and actor from dedicated models, preserves schema invariants, uses normalized Category/Product slugs and demo-prefixed SKUs as stable keys, and produces no credentials.

Demo Categories form this eight-node tree: Arbetskläder (Arbetshandskar, Skyddskläder), Säkerhetsutrustning (Brandskydd, Hörselskydd), and Belysning (Arbetsbelysning). It creates exactly five draft Products:

| Product | Primary Category | Default SKU |
|---|---|---|
| ProGrip Work Gloves | Arbetshandskar | `DEMO-GLOVE-PRO-M` |
| Reflective Safety Vest | Skyddskläder | `DEMO-VEST-YELLOW-L` |
| Fire Extinguisher 6 kg | Brandskydd | `DEMO-FIRE-6KG` |
| Professional Ear Defenders | Hörselskydd | `DEMO-EAR-PRO` |
| Industrial LED Work Lamp | Arbetsbelysning | `DEMO-LAMP-40W` |

All demo GTINs, publication/media pointers, attributes, prices, and inventory are absent. A second run updates the same stable records and creates no duplicates.

## Database and application guarantees

MySQL guarantees same-Company foreign keys for aggregate pointers and pivots, normalized slug/SKU uniqueness, required pointer relationships, enum/check constraints, and the schema triggers introduced in R1.2. No catalog migration is changed by R1.5.

Application code guarantees authorization, current tenant resolution, exact field limits, slug normalization, draft-only creation, mandatory default Variant creation, active tenant Category validation, the 20-Category limit, primary-in-pivot consistency, actor attribution, transactional audit, rollback, no-op behavior, bounded pagination, and eager-loaded list rows.

## Test profiles

Product Action, HTTP/UI, pivot, rollback, Seeder, and full Catalog tests run against the dedicated MySQL database `nordipass_testing`. SQLite is not configured or supported. The bootstrap rejects a non-MySQL driver or a database name without the `_testing` suffix. The CI backend job starts MySQL 8 and automatically includes `tests/Feature/Catalog/Products` and `tests/Unit/Catalog/Products` through the full suite.

Focused commands (using the project's PHP CLI configuration with `pdo_mysql` enabled):

```bash
php vendor/pestphp/pest/bin/pest tests/Feature/Catalog/Products tests/Unit/Catalog/Products
php vendor/pestphp/pest/bin/pest tests/Feature/Catalog tests/Unit/Catalog
php vendor/pestphp/pest/bin/pest
```

## Deferred functionality

- Product publish/activate/archive/delete lifecycle;
- Variant create/update/archive and default-Variant switching;
- Attribute definition/value management;
- media and document upload;
- price and inventory management;
- public or token-authenticated Product API;
- bulk operations, import/export, localization, and R2+ workflows.
