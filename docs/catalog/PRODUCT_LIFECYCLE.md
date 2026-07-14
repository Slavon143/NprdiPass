# Product and Variant Lifecycle (R1.9)

## Scope and meaning of active

R1.9 adds internal catalog lifecycle management. `active` means that a Product passed the internal quality/readiness rules at activation time. It is not anonymous publication, public distribution, DPP issuance, QR availability, API exposure, pricing, inventory, or document publication. Those concerns remain deferred.

This document is authoritative for lifecycle behavior introduced in R1.9 and supersedes provisional lifecycle examples in earlier planning documents where they conflict with the implemented R1.9 transitions.

## State diagrams

```text
Product
draft --Activate + readiness--> active
active --Return to draft------> draft
draft --Archive---------------> archived
active --Archive--------------> archived
archived --Restore------------> draft
```

Direct `archived -> active`, `archived -> return-to-draft`, and ordinary form-driven status changes are forbidden. Restore deliberately returns to draft so readiness must be checked again by a separate activation.

```text
Variant
draft/active --Archive--------> archived
archived --Restore------------> active
draft --Product activation----> active
```

Variant lifecycle is structurally independent after Product activation. An active Variant below an archived Product remains structurally active but is effectively unavailable. Product archive does not rewrite Variant status.

## Transitions and permissions

| From | To | Action | Permission | Readiness |
|---|---|---|---|---|
| Product draft | active | `ActivateProductAction` | `catalog.publish` | Required inside transaction |
| Product active | draft | `ReturnProductToDraftAction` | `catalog.publish` | Not required |
| Product draft/active | archived | `ArchiveProductAction` | `catalog.archive` | Not required |
| Product archived | draft | `RestoreProductAction` | `catalog.archive` | Not run automatically |
| Variant draft/active | archived | `ArchiveProductVariantAction` | `catalog.archive` | Aggregate invariants only |
| Variant archived | active | `RestoreProductVariantAction` | `catalog.archive` | Product activation is unchanged |

Owner and Admin have publish/archive permissions. Editor and Viewer do not. Any active Company member with `catalog.view` may view status and readiness. Policies and Actions independently enforce active User, active Company, active Membership, CurrentCompany, tenant ownership, and permission. Wrong-tenant and wrong-Product route identifiers are resolved through tenant-scoped queries and concealed as 404.

Repeated operations already at their target state are successful no-ops: no UPDATE and no duplicate audit. A forbidden jump remains a domain error.

## Activation readiness

`ProductActivationReadinessService` is read-only. It authorizes nothing, opens no transaction, writes no audit, assigns no pointer, creates no value, and repairs nothing. It returns `ready`, structured `blockers`, separate `warnings`, and `checked_at`. Each item has a stable code, message, section, entity type, and optional entity UUID.

Hard blockers:

| Code | Condition |
|---|---|
| `invalid_product_status` | Product is not draft |
| `missing_product_name` | Name is empty |
| `missing_product_slug` | Slug is empty or not normalized |
| `missing_primary_category` | No primary Category |
| `invalid_primary_category` | Primary Category is not owned by the Company/Product or absent from the pivot |
| `archived_primary_category` | Primary Category is archived |
| `missing_default_variant` | Default pointer is absent |
| `invalid_default_variant` | Default Variant has wrong ownership or is archived |
| `no_available_variants` | No non-archived Variant remains |
| `missing_required_product_attribute` | Active required Product/both Definition has no value |
| `missing_required_variant_attribute` | Active required Variant/both Definition has no default-Variant value |
| `invalid_attribute_value` | Stored typed value/options/rules are invalid |
| `archived_attribute_option` | Required select/multiselect uses an archived Option |
| `missing_primary_media_file` | A configured primary pointer is invalid or its private file is missing |

R1.1 requires Variant attributes only on the default Variant. Archived Definitions do not participate. Product media and SKU are soft gates, not blockers. GTIN and MPN are optional.

Warnings include `missing_variant_sku`, `missing_variant_gtin`, `missing_primary_media`, `missing_product_brand`, `missing_product_manufacturer`, and `archived_secondary_category`. Warnings never prevent activation.

Readiness loads Product relations, required Definitions, values, and options in bounded eager-loaded queries. It does not query once per Definition/Variant and checks filesystem existence only for a configured Product primary pointer; file contents are not loaded.

## Transactions and locking

Activation authorizes before entry and again in the transaction, locks the Product, locks Variant rows by ascending ID, reloads all readiness dependencies, and evaluates readiness again. A blocked activation leaves Product, Variants, `updated_by`, pointers, values, relations, media, and audit unchanged. Successful activation preserves the first `published_at`, promotes non-archived draft Variants to active, updates the Product, and writes one audit atomically.

Variant archive locks Product first, then every Variant row by ascending ID. It reads the current `products.default_variant_id` under lock and recounts non-archived Variants under the same lock. The default Variant and last available Variant cannot be archived; no automatic default promotion occurs. Restore locks Product and Variant and does not change the default pointer.

Product archive/restore lock only lifecycle state and retain the aggregate. Archive never removes or rewrites Categories, attribute values, Media/files, default Variant, primary Category, primary Media, or Variant statuses. Variant identifiers remain reserved because archived rows retain SKU/normalized SKU, GTIN, and MPN under the existing unique constraints.

## Editing policy

Archived Product and archived Variant records are read-only through ordinary Product, Category sync, Variant, Attribute, Media, and default-Variant Actions. `CatalogLifecycleGuard` is called in the Action layer, including after row locks; policies also hide/reject edit routes. Restore the Product to draft or restore the Variant before editing.

R1.1 explicitly permits in-place edits on active Products with audit, so R1.9 preserves that policy rather than introducing an unapproved hidden auto-draft. Such edits can make the aggregate fail current readiness; readiness is recomputed from current data in the Product screen and is mandatory again on the next activation cycle.

## Audit

| Event | Trigger | Safe metadata |
|---|---|---|
| `catalog.product.activated` | draft to active | UUID, statuses, Variant/required-check counts, warning codes |
| `catalog.product.returned_to_draft` | active to draft | UUID and statuses |
| `catalog.product.archived` | draft/active to archived | UUID and statuses |
| `catalog.product.restored` | archived to draft | UUID and statuses |
| `catalog.variant.archived` | non-default, non-last to archived | Product/Variant UUID, nullable SKU, statuses, `was_default` |
| `catalog.variant.restored` | archived to active | Product/Variant UUID, nullable SKU, statuses, `was_default` |

No event stores raw requests, full models, attribute values, files, secrets, or credentials. Audit creation shares the transition transaction.

## UI states

Product index/show/edit use the existing status badges. Product show calculates one current readiness checklist and presents blockers and warnings separately. Draft offers Activate/Archive, active offers Return to draft/Archive, and archived offers Restore to draft. Viewer/Editor mutation controls follow the permission matrix. UI disabling is informative only; Actions remain authoritative.

Variant index/show display status and default badges. Default and last-available Variants explain why archive is unavailable. Archived Variants offer Restore. Authenticated Company users may still view archived Product media through the private content route.

## Demo lifecycle

| Demo record | Final state |
|---|---|
| ProGrip Work Gloves | active |
| Reflective Safety Vest | active |
| Fire Extinguisher 6 kg | draft |
| Professional Ear Defenders | archived; its Variant stays structurally active/effectively unavailable |
| Industrial LED Work Lamp | draft |
| Reflective Safety Vest / Orange Large | archived Variant; identifiers and attributes retained |

The seeder remains restricted to local/testing, uses only `NordiPass Demo AB`, validates active candidates with the readiness service, has deterministic final pointers/states, and writes no lifecycle audit. Repeated seed runs create no duplicate entities or audits.

## Guarantees

Database guarantees are limited to the real MySQL 8 constraints: allowed status values; tenant/Product ownership foreign keys; default Variant, primary Category, and primary Media referential ownership; identifier uniqueness; and attribute referential integrity. The database does not guarantee readiness, required values, active Categories/options, a non-archived default, a minimum Variant count, physical file existence, or allowed transitions.

Application guarantees are allowed transitions/no-ops, readiness and stable codes, active primary Category/pivot rules, default availability, required Product/default-Variant attributes, optional identifier/media warnings, broken-primary blocking, minimum available Variant count, default archive protection, non-cascading Product archive, restore targets, archived mutation guards, transactional audit, and stale-state revalidation.

All lifecycle, Catalog, and application profiles run against the explicitly guarded MySQL testing database. No SQLite profile, branch, driver skip, or fallback is introduced. Public publication, hard delete, lifecycle API, bulk/scheduled workflow, search, QR, DPP, Documents/PDF, notifications, pricing, and inventory remain deferred to later stages.
