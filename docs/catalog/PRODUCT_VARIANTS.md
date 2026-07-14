# NordiPass R1.6 — Product Variants and Identifiers

**Stage:** R1.6  
**Date:** 2026-07-14  
**Database:** MySQL 8 only

## Scope

R1.6 adds tenant-safe web management for Product Variants: nested listing, create, show, edit, explicit default selection, and SKU/GTIN/MPN handling. It extends the mandatory Product→Variant aggregate introduced in R1.3/R1.5 without adding lifecycle operations.

Variant archive/restore/delete, Product lifecycle, attributes and combinations, media, pricing, inventory, search, API, bulk operations, documents, QR, and DPP are deferred.

## Routes and UI

All routes run inside the existing authenticated, verified, resolved/selected Company, active membership, and active Company middleware group.

| Method | Route name | Purpose |
|---|---|---|
| GET | `catalog.products.variants.index` | Ordered, tenant- and Product-scoped Variant list, paginated at 25 |
| GET | `catalog.products.variants.create` | Create form with Product/default context |
| POST | `catalog.products.variants.store` | Create one draft Variant |
| GET | `catalog.products.variants.show` | Identifier, state, default, actor, and timestamp details |
| GET | `catalog.products.variants.edit` | Edit managed Variant fields |
| PATCH | `catalog.products.variants.update` | Update managed fields |
| POST | `catalog.products.variants.set-default` | Explicitly change the Product pointer |

There are no DELETE, archive, restore, or GET mutation routes. Index rows show the display label, identifiers, status, sort order, default badge, timestamp, and authorized actions. Product show displays a bounded first five ordered Variants, total count, and management links. Product index uses `withCount('variants')` and the already eager-loaded default Variant rather than loading every Variant.

## Permissions and tenant resolution

| Operation | Owner | Admin | Editor | Viewer | Permission |
|---|---:|---:|---:|---:|---|
| list/show | allow | allow | allow | allow | `catalog.view` |
| create | allow | allow | allow | deny | `catalog.create` |
| update | allow | allow | allow | deny | `catalog.update` |
| set default | allow | allow | allow | deny | `catalog.update` |

Controllers resolve the Product with `Product::forCompany($currentCompany)`, then resolve the Variant with the same Company plus `product_id`. A foreign Company UUID or a Variant nested under the wrong Product is concealed as 404. Policies and Actions independently re-read persisted ownership and repeat CurrentCompany, active User, active Company, fresh membership, and role authorization.

## Variant fields

The actual R1.2 schema fields used by R1.6 are:

| Field | User-managed | Rule |
|---|---:|---|
| `name` | yes | optional, trimmed, maximum 255 |
| `sku` | yes | optional display value, maximum 100 |
| `sku_normalized` | no | generated from SKU for Company uniqueness |
| `gtin` | yes | optional GTIN-8/12/13/14 |
| `mpn` | yes | optional, maximum 100 |
| `sort_order` | yes | unsigned non-negative integer |
| `status` | no | new Variants are server-controlled `draft` |
| tenant, Product, actor, media, deleted fields | no | always trusted/system-controlled |

Name may be null. UI display resolution is name, then SKU, then the first eight characters of UUID. No localized fallback text is persisted automatically.

## Default Variant invariant

`products.default_variant_id` is the sole application source of truth. The physical legacy `product_variants.is_default` column remains untouched: it is hidden, not fillable/cast, never queried for default state, and never mutated. No second flag or cached default mechanism is introduced.

Every committed Product continues to point to a Variant belonging to the same Company and Product. New additional Variants do not replace that pointer. Default selection locks and re-reads Product, then locks the target Variant; it rejects foreign, wrong-Product, archived, soft-deleted, or unauthorized targets. Selecting the current default is a successful no-op with no UPDATE or audit event.

## Identifier rules

| Identifier | Owner | Required | Normalization | Validation | Uniqueness |
|---|---|---:|---|---|---|
| SKU | Variant | no | trim display; validate characters; remove internal whitespace and uppercase normalized mirror | letters/numbers/dot/underscore/hyphen/whitespace, max 100 | `UNIQUE(company_id, sku_normalized)`; multiple nulls |
| GTIN | Variant | no | trim only; punctuation is not stripped | digits only, length 8/12/13/14, GS1 check digit | `UNIQUE(company_id, gtin)`; multiple nulls |
| MPN (`mpn`) | Variant | no | trim outer whitespace, preserve case/internal whitespace | max 100 | none |

Soft-deleted and future archived identifier values remain occupied because the database rows retain them. The same non-null SKU or GTIN is allowed in another Company.

### GTIN check digit

`GtinValidator` works without external services:

1. separate the last digit as the supplied check digit;
2. iterate the body from right to left;
3. multiply alternating digits by 3, then 1, beginning with 3 at the rightmost body digit;
4. sum the products;
5. calculate `(10 - (sum mod 10)) mod 10`;
6. compare it with the supplied digit.

The validator exposes `isValid`, `assertValid`, and `calculateCheckDigit`. It preserves leading zeros and distinguishes digit, length, and check-digit errors. The MySQL CHECK validates only digit shape and length; check-digit correctness is an application guarantee.

## Transactions and locks

### Create

`CreateProductVariantAction` authorizes against the trusted Company and Product, starts a transaction, re-authorizes, locks/re-reads the tenant Product, recounts Variants, enforces the 100-Variant limit, normalizes identifiers, inserts a trusted draft Variant with actor fields, and writes `catalog.variant.created`. The default pointer is not changed.

### Update

`UpdateProductVariantAction` validates Company/Product/Variant ownership before the transaction, then re-authorizes and locks Product followed by the nested Variant. It normalizes only managed fields, preserves every system field, performs no write for an exact no-op, and writes `catalog.variant.updated` with field names only.

### Set default

The single foundation `SetDefaultProductVariantAction` is reused. It locks Product first and target Variant second, reads the actual current pointer under lock, validates ownership/status, updates only the Product pointer and actor, and writes `catalog.variant.default_changed`. The consistent Product→Variant lock order is used by update/default operations.

The Product lock serializes concurrent Variant creation so two stale requests cannot both pass the 100-Variant limit.

## Constraint conflict mapping and audit

MySQL remains the final race-safe uniqueness authority. Driver error 1062 is mapped by the actual constraint name:

- `variants_company_sku_unique` → `duplicate_sku` on `sku`;
- `variants_company_gtin_unique` → `duplicate_gtin` on `gtin`.

Unknown constraints and non-duplicate database failures are rethrown. SQLSTATE, SQL, table names, and constraint names are never rendered in HTTP errors.

Create audit metadata contains Product/Variant UUID, safe display name/SKU, boolean GTIN/MPN presence, and status. Update contains UUIDs and changed field names. Default change contains Product UUID and old/new default UUID. Full GTIN, MPN, normalized identifiers, request payloads, headers, and database errors are not logged. Audit and mutation always commit or roll back together.

## Demo variants

`CatalogDemoSeeder` remains local/testing-only, dedicated to `NordiPass Demo AB`, transactional, audit-free, and idempotent. Stable Company, Product slug, and normalized demo SKU identify records.

| Product | Variant | SKU | GTIN | MPN | Default |
|---|---|---|---|---|---:|
| ProGrip Work Gloves | Medium | `DEMO-GLOVE-PRO-M` | null | `NS-GLOVE-PRO-M` | yes |
| ProGrip Work Gloves | Large | `DEMO-GLOVE-PRO-L` | null | `NS-GLOVE-PRO-L` | no |
| ProGrip Work Gloves | Extra Large | `DEMO-GLOVE-PRO-XL` | null | `NS-GLOVE-PRO-XL` | no |
| Reflective Safety Vest | Yellow / Large | `DEMO-VEST-YELLOW-L` | null | `NS-VEST-YL-L` | yes |
| Reflective Safety Vest | Yellow / Medium | `DEMO-VEST-YELLOW-M` | null | `NS-VEST-YL-M` | no |
| Reflective Safety Vest | Orange / Large | `DEMO-VEST-ORANGE-L` | null | `NS-VEST-OR-L` | no |
| Fire Extinguisher 6 kg | 6 kg | `DEMO-FIRE-6KG` | null | `SG-FE-6KG` | yes |
| Professional Ear Defenders | Professional | `DEMO-EAR-PRO` | null | `SS-EAR-PRO` | yes |
| Industrial LED Work Lamp | 40 W | `DEMO-LAMP-40W` | null | `NL-WORK-40` | yes |
| Industrial LED Work Lamp | 60 W | `DEMO-LAMP-60W` | null | `NL-WORK-60` | no |

A second run preserves Product/Variant IDs, counts, SKUs, and default pointers. It creates no attributes, media, pricing, inventory, or audit history.

## Guarantees

### MySQL guarantees

- composite `(company_id, product_id)` Variant→Product foreign key;
- Company-scoped normalized SKU and GTIN unique indexes with MySQL multiple-NULL behavior;
- composite Product default pointer FK `(company_id, id, default_variant_id)` to Variant `(company_id, product_id, id)`;
- status and non-negative sort-order CHECK constraints;
- GTIN digits/length CHECK;
- identifier column length limits and actor/media foreign keys.

### Application guarantees

- trusted Company, Product, lifecycle, media, and actor assignment;
- nested tenant/Product resolution and concealment;
- SKU and MPN normalization;
- GS1 GTIN check-digit validation;
- maximum 100 Variants after a Product lock and fresh count;
- Product/Variant re-read and locks for update/default;
- Variant relocation prevention;
- single default pointer and idempotent switch;
- race-safe constraint mapping;
- no-op updates without audit;
- transactional audit and rollback;
- bounded/eager-loaded UI queries.

## MySQL-only testing

Variant unit, Action, route/UI, concurrency/stale-state, Seeder, full Catalog, and full application tests use `nordipass_testing` on MySQL. The test bootstrap rejects SQLite, non-MySQL connections, and database names without `_testing`. The existing CI service starts MySQL 8 and includes Variant tests through the standard full suite.

## Deferred

- Attribute definitions/values, option combinations, and automatic matrix generation;
- Variant media and primary-image workflows;
- Variant and Product lifecycle transitions;
- search/listing stage and Catalog API;
- pricing, stock, warehouses, import/export, and bulk edit;
- documents, QR, DPP, and external-system synchronization.
