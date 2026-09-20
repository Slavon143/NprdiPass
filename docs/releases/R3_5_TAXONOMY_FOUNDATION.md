# R3.5 Taxonomy Governance — Foundation Slice

**Status:** ACCEPTED  
**Date:** 2026-08-20

## Included

- Versioned, tenant-safe category templates with one active version per category.
- Required and recommended template attributes.
- Validated DPP section defaults stored on draft templates.
- Readiness profile code/version/fingerprint assignment.
- Product profile override and inherited profile resolver for newly created Passport drafts.
- Attribute and option lifecycle states with replacement references.
- Controlled-vocabulary approval state.
- Product required/recommended diagnostics and explicit needs-review acknowledgement.
- Activated-template immutability and draft-only child editing enforced by MySQL.
- Upgrade-safe defaults for existing definitions, options, categories, and products.
- Published Passport payload/evidence isolation.
- Authorized/audited governance actions and tenant-scoped API resources.
- Category template editor with draft cloning, attribute requirements, DPP defaults, activation, and version history.
- Product Needs Review diagnostics and acknowledgement guard.
- Attribute/option lifecycle and controlled-vocabulary approval UI.
- OpenAPI and mobile/desktop browser acceptance evidence.

## Boundaries retained

- No automatic product value assignment or migration.
- DPP defaults are stored as template policy; they are not copied into existing Passport drafts.
- No universal taxonomy migration engine.
- Existing published Passports remain immutable.

## Next task

Proceed to R3.6 Production Import/Export. Import validation must consume active/selectable attribute definitions, approved controlled-vocabulary options, category-template required/recommended semantics, and the R3.5 product needs-review contract.

See `docs/architecture/TAXONOMY_GOVERNANCE.md` and `docs/testing/R3_5_TAXONOMY_TEST_EVIDENCE.md`.
