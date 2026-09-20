# R3.5 Handoff — Taxonomy and Attribute Governance

## Entry status

R3.4.1 provided the green baseline. R3.5 taxonomy and attribute governance is now implemented and accepted. The next release task is R3.6 Production Import/Export.

The authoritative contract is `docs/architecture/adr/R3-005-taxonomy-governance.md`. R3.5 consumes the active readiness profile contract introduced in R3.2 and hardened in R3.4.1.

## R3.5 completed

The versioned category-template data model, governance application layer, API, and UI are implemented.

The first vertical slice includes:

1. `category_templates`, template-to-attribute assignments, and template DPP defaults with tenant-safe foreign keys.
2. Required versus recommended attribute semantics and an assigned readiness profile/version that resolves through `ReadinessProfileRepository`.
3. Attribute and option lifecycle states (`active`, `deprecated`, `archived`) plus replacement references.
4. Database and domain guards that prevent deletion of referenced options and reject invalid profile/category assignments.
5. Upgrade/backfill tests proving existing categories, products, attributes, drafts, and published Passports remain unchanged.
6. Authorized and audited API operations for draft/configure/activate, product diagnostics/acknowledgement/profile override, definition deprecation, and option approval/deprecation.
7. Category template editor, Product Needs Review card, and controlled-vocabulary lifecycle UI.
8. OpenAPI coverage for 94 catalog operations and deterministic browser acceptance on mobile and desktop.

## Non-negotiable boundaries

- No automatic product value migration or invented defaults.
- Existing products become `needs review` when their category requirements change.
- Published Passport payloads and their readiness evidence are immutable.
- Deprecated values remain readable for existing products but are not offered for new assignment.
- Archived values remain historical/read-only.
- Product-level readiness-profile override is explicit and auditable.
- No universal taxonomy migration engine; that remains outside the R3.5 scope.

## Next task

Begin R3.6 Production Import/Export using the stabilized R3.5 attribute and taxonomy contracts. The first slice should define tenant-scoped import jobs, mapping templates, preview/write parity, row-level validation/error reports, and idempotent resume boundaries before building the upload UI.

## R3.5 final definition of done

- The R3.5 release gate in `docs/releases/R3_RELEASE_GATES.md` passes: required-attribute diagnostics, safe option lifecycle, no universal migration engine, and profile/category assignment integrity.
- Migration rollback/upgrade compatibility and tenant isolation are covered on MySQL.
- Readiness resolution tests cover inherited and overridden profile assignments without mutating historical evidence.
- UI and API contracts expose the same lifecycle and diagnostics.
- Full PHP, static analysis, formatting, dependency audit, build, and browser acceptance gates remain green.

Final evidence is in `docs/testing/R3_5_TAXONOMY_TEST_EVIDENCE.md` and `docs/testing/evidence/r3_5_browser_acceptance.json`.
