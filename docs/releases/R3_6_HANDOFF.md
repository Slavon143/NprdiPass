# R3.6 Handoff — Production Import/Export

## Entry status

R3.5 provided the accepted taxonomy and attribute-governance baseline. R3.6 Production Import/Export is implemented and accepted. It has passed the automated code/data/API gates and the responsive browser workflow, with evidence recorded under `docs/testing/evidence/`.

The authoritative scope is `docs/releases/R3_IMPLEMENTATION_CONTRACTS.md` together with ADR-R3-011. R3.6 consumes the active category, attribute lifecycle, controlled-vocabulary, and product validation contracts established by R3.5.

## Completed implementation

1. Tenant-safe import job, mapping-template, row, and row-error persistence with composite foreign keys and database constraints.
2. Private CSV/XLSX upload storage, content hashing, MIME validation, spreadsheet/ZIP hardening, and configurable limits.
3. Shared deterministic row planning for preview and write, including categories and active product attributes.
4. Create/update/upsert modes, upload idempotency, duplicate detection, chunked application, persisted resume cursor, and row-level retry safety.
5. Reusable web/API mapping templates, downloadable error reports, and import-compatible tenant CSV/XLSX exports.
6. Feature gating, authorization, token abilities, cross-tenant 404 behavior, audit events, OpenAPI, UI, and sidebar integration.
7. Full MySQL, static-analysis, formatting, production-build, and dependency-security verification.

## Operational notes

- Production activation is controlled by `FEATURE_PRODUCTION_IMPORTS`; its non-local default remains off until the rollout is explicitly enabled.
- The private import filesystem disk is `production_imports`.
- Import limits are configured in `config/imports.php` and must be sized together with PHP upload limits, worker timeout/memory, and storage retention before production enablement.
- Applying a job is resumable and idempotent, but the current implementation runs within the request lifecycle. Queue orchestration and job-center integration belong to the later R3.14 contract.
- Temporary import-file retention/cleanup should be connected to platform operations before broad production rollout.

## Acceptance action

`storage/framework/r3_6_acceptance.mjs` passed against the testing server. The generated JSON report and responsive screenshots are preserved under `docs/testing/evidence/`; the report has no console errors, failed requests, HTTP 500 responses, or overflow.

## Next release task

Begin R3.7 Fortnox integration. The first vertical slice should establish OAuth state validation, encrypted credentials, tenant-scoped connections and article identity mappings, explicit source-of-truth rules, conflict/reconciliation records, bounded retries, and audit history. Do not begin scheduled synchronization before these boundaries have focused failure-path tests.

## Definition of done

- R3.6 release gate: preview before write, idempotency, resume, chunk boundaries, large-file MySQL coverage, and tenant-scoped private storage.
- Import/export parity for core product fields, category, and active approved attribute vocabularies.
- Tenant isolation and authorization at policy, API ability, action, storage, and database layers.
- Full suite, PHPStan, Pint, build, dependency audits, migration cycle, and browser workflow green.
- Evidence recorded in `docs/testing/R3_6_IMPORT_EXPORT_TEST_EVIDENCE.md` and `docs/testing/evidence/r3_6_browser_acceptance.json`.
