# NordiPass R1.7 — Product and Variant Attributes

**Stage:** R1.7  
**Date:** 2026-07-14  
**Database:** MySQL 8 only

## Scope

R1.7 provides tenant-owned reusable Attribute Definitions, predefined Options, typed Product and Variant values, management and assignment Blade screens, policy authorization, transactional audit, and deterministic demo data. It does not add inheritance, automatic Variant combinations, media, lifecycle/publication, attribute search, APIs, imports, pricing, or inventory.

## Model and storage

`AttributeDefinition` is identified publicly by UUID and belongs to one Company. The real R1.2 fields are `name`, normalized `code`, `description`, `type`, `scope`, `unit`, `required`, `filterable`, `searchable`, `validation_rules`, `sort_order`, `status`, and actor timestamps. The normalized machine identifier is stored directly in `code`; there is no parallel `code_normalized` column.

`AttributeOption` belongs to the same Company and Definition. The R1.2 table has no UUID or actor columns, so web routes resolve its numeric ID only after resolving the tenant-owned Definition. An Option can never move between Definitions.

| Type | Typed storage | UI |
|---|---|---|
| text | `value_text` | text input |
| integer | `value_integer` | integer input |
| decimal | `value_decimal` (`DECIMAL(20,4)`) | decimal text input |
| boolean | `value_boolean` | Yes / No / Not set |
| date | `value_date` (`Y-m-d`) | date input |
| select | `value_option_id` | native select |
| multiselect | value row plus Product/Variant option pivot | native checkboxes |

Every write explicitly nulls unused typed columns. Trimmed empty text, blank scalar/select input, and an empty multiselect mean clear: the value row is deleted and pivot rows cascade/delete in the same transaction. Decimal input is validated as a string and normalized to four fractional digits without float conversion.

## Scope and required semantics

| Scope | Product | Variant |
|---|---:|---:|
| product | allowed | denied |
| variant | denied | allowed |
| both | independent value | independent value |

There is no fallback or inheritance between Product and Variant. `required` does not block draft edits or clearing. The UI displays `Missing required value`; publication readiness remains R1.9 work.

## Validation rules

`validation_rules` is structured JSON produced from typed form inputs. Arbitrary Laravel rules and regular expressions are rejected.

| Type | Allowed rules |
|---|---|
| text | `min_length`, `max_length` (0–1000) |
| integer / decimal | `min`, `max` |
| date | `min_date`, `max_date` |
| multiselect | `min_selections`, `max_selections` (0–200) |
| boolean / select | none |

`AttributeValueValidator` performs scope, type, Definition state, Option tenant/owner/state, precision, range, selection-count, and normalization checks and returns `NormalizedAttributeValue`. It performs no authorization, transaction, persistence, CurrentCompany lookup, or audit.

## Sync contract and locking

Product and Variant forms use full replacement for all active compatible Definitions displayed on that form. A missing active Definition key is an explicit clear. Values for archived Definitions are excluded from the replacement set, remain stored, and are displayed read-only.

Product sync locks Product, active compatible Definitions, and existing Product values. Variant sync locks Product, then Variant, Definitions, and existing Variant values. All inputs are normalized before writes. Scalar/select upsert, multiselect pivot replacement, clears, and one audit event share the transaction. A failure leaves old rows/pivots unchanged and emits no audit. Exact repeat sync is a no-op without timestamp or audit changes.

## Mutation restrictions and archive policy

- Definition code becomes immutable after any Product or Variant value exists.
- Type becomes immutable after any Option or value exists.
- Scope changes are rejected when the new scope would exclude existing Product or Variant values.
- Option code becomes immutable after select or multiselect use.
- Options exist only for select/multiselect Definitions.
- Definition and Option archive preserve existing values, pivots, labels, and identifiers.
- Archived Definitions/Options cannot receive new assignments.
- Definition restore does not restore Options; Option restore requires an active Definition.
- Archive, restore, reorder, update, and sync no-ops do not emit duplicate audit.

## Routes and UI

Definitions are managed at `/catalog/attributes`; nested Options have store/update/archive/restore/reorder mutation routes. Product assignment is `/catalog/products/{product}/attributes/edit`; Variant assignment is nested below its Product and Variant. All routes use the existing authenticated, verified, selected active Company and active membership middleware stack. Wrong-tenant and wrong-Product identifiers are concealed as 404.

The navigation exposes `Attributes` to `catalog.view`. Index/show pages are available read-only to all Company roles. Mutation controls follow policy checks. Definition forms expose typed validation inputs rather than raw JSON. Product/Variant show screens eager-load typed values, selected Options and multiselect Options, format values with units, and show required/archived state.

## Permissions

| Operation | Owner | Admin | Editor | Viewer | Permission |
|---|---:|---:|---:|---:|---|
| view Definitions/values | allow | allow | allow | allow | `catalog.view` |
| manage Definitions/Options | allow | allow | deny | deny | `catalog.manage_attributes` |
| assign Product/Variant values | allow | allow | allow | deny | `catalog.update` |

Policies re-read persisted tenant ownership. Actions independently repeat active User, active Company, fresh membership, CurrentCompany, role, and trusted Company checks.

## Audit

R1.7 emits Definition create/update/archive/restore, Option create/update/archive/restore/reorder, Product attributes updated, and Variant attributes updated events. Metadata contains UUIDs/codes, changed field names, and changed/cleared attribute codes. It never contains raw text attribute values, full payloads, SQL, tokens, or credentials.

## Demo data

The local/testing-only `CatalogDemoSeeder` creates exactly six Definitions for `NordiPass Demo AB`: Size (variant select, required; S/M/L/XL), Color (variant select; Black/Yellow/Orange), Material (product select; Nitrile/Polyester/Steel/ABS plastic), Weight (product decimal, kg), Power (variant integer, W), and Certifications (product multiselect; CE/EN 388/EN ISO 20471). It assigns the specified gloves, vest, extinguisher, and lamp Product/Variant values. Stable Company, code, Product slug, and normalized SKU keys make repeat runs idempotent. No real tenant is selected implicitly and production execution is refused.

## Guarantees

MySQL guarantees same-Company Definition/Option/entity ownership through composite foreign keys, Option-to-Definition consistency, one value row per entity/Definition, at most one scalar typed column, multiselect Option referential integrity, and duplicate-pivot rejection.

The application guarantees scope/type/rule enforcement, missing-required detection, active Definition/Option selection, exact typed-column choice, tenant/Product/Variant validation, atomic multiselect sync, transactional audit, and mutation restrictions. MySQL does not guarantee scope, Definition type matching, required presence, validation rules, or archive behavior.

## MySQL-only tests and deferred work

The default and focused profiles use `APP_ENV=testing`, `DB_CONNECTION=mysql`, and the explicit `nordipass_testing` database. Test bootstrap rejects SQLite/non-MySQL drivers and database names without `_testing`. CI runs migration/seed, focused Attribute tests, full Catalog tests, and the full application suite.

Deferred: media, lifecycle/publication readiness, required-value activation gates, attribute search/facets, Attribute API, automatic Variant combinations/matrix UI, import/export, pricing, inventory, Documents, QR, and DPP.
