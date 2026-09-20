# R3.6 Production Import/Export

**Status:** ACCEPTED  
**Date:** 2026-08-20

## Included

- Tenant-scoped CSV and XLSX import jobs, row records, validation errors, and reusable mapping templates.
- Hardened private upload storage with MIME/extension checks, size and row limits, source SHA-256 verification, XLSX formula rejection, and ZIP expansion limits.
- Deterministic preview/write planning with row-level diagnostics and downloadable error CSV reports.
- Create, update, and upsert modes with content-bound idempotency and duplicate-in-file protection.
- Transactional chunk application, persisted cursor/counters, safe resume, and already-applied row protection.
- Core product, primary-category, and active product-attribute mappings.
- Controlled-vocabulary validation against active and approved selectable options.
- Partial updates that preserve aggregate roots and values for fields omitted from the mapping.
- Tenant-scoped CSV and XLSX exports that round-trip through the importer and neutralize spreadsheet formula injection.
- Feature-gated web UI and additive `/api/v1/catalog` endpoints with dedicated token abilities.
- Additive OpenAPI coverage for 103 catalog operations.
- MySQL migration, rollback/upgrade, large-file, cross-tenant, security, API, export, and regression coverage.

## Release boundaries retained

- The feature is disabled by default outside local/testing until production rollout is explicitly enabled.
- Import never bypasses company permissions, tenant ownership, active taxonomy definitions, or controlled-vocabulary approval.
- Preview and write use the same normalized row plan; the write phase rejects changed source content or a stale plan fingerprint.
- Existing published Passport snapshots and historical taxonomy evidence are not mutated.
- Real-time ERP synchronization is outside R3.6 and begins with R3.7 Fortnox.

## Acceptance gate

The code, schema, API, security, static-analysis, build, dependency, full MySQL regression, and responsive browser workflow gates are green.

The deterministic acceptance harness at `storage/framework/r3_6_acceptance.mjs` passed and wrote `docs/testing/evidence/r3_6_browser_acceptance.json` plus responsive screenshots. It covers login, CSV upload, mapping, invalid-row preview, error-report download, mapping-template save, two-chunk apply/resume, CSV/XLSX exports, responsive screenshots at 375px and 1280px, console/network failures, and horizontal overflow.

## Next task

Start R3.7 Fortnox integration from the OAuth state, encrypted token storage, tenant-scoped identity mapping, and source-of-truth matrix contracts before implementing article synchronization.

See `docs/testing/R3_6_IMPORT_EXPORT_TEST_EVIDENCE.md` and `docs/releases/R3_6_HANDOFF.md`.
