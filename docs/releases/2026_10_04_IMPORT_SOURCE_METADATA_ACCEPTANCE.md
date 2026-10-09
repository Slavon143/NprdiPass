# Import source metadata acceptance — 2026-10-04

This closes the next contract clarification item after native identifier import. It is not an implementation of supplier lifecycle ingestion, timestamp conflict resolution or bulk remote document attachment. Those policies require separate domain decisions. Current behavior is specified in [source metadata boundary](../architecture/IMPORT_SOURCE_METADATA.md), API prose and OpenAPI.

## Change

The import page now explains the status, timestamp and document boundaries for both writers and readers. After a persisted preview it lists headers not consumed by that mapping. The list is based on the saved plan and uses escaped header names; unsaved form selections are not treated as applied mappings. The additional source `updated_at` target is covered by explicit mapping rejection alongside status and document reference. Existing domain mutations remain unchanged.

New products start as drafts. Update/upsert preserve current status and cannot edit/restore archived products. Product timestamps remain the time of application changes. A source timestamp is not a freshness watermark: stale source rows can still change mapped fields. `document_ref` remains text and causes no HTTP request, file attachment, document version or approval. The original stored file and persisted source row retain unconsumed data. API rows expose mapped data rather than raw source rows.

## Executed verification

- Focused source metadata and API tests: 10 passed / 100 assertions.
- Adjacent imports, native identifiers, reader/writer, import API and product lifecycle: 89 passed / 546 assertions. This includes the focused tests; do not add the counts as unique tests.
- Regression fixtures check create/update with source `status=archived`, an old timestamp and a link to a metadata-service address. The source values survive, mapped product fields update, status/application timestamps obey the contract, no documents/versions appear and no HTTP requests occur.
- An archived-product fixture with source `status=active` remains archived and unchanged; the failed row is counted rather than restored.
- API requests explicitly targeting status, updated_at or document_ref return 422 `validation_error` with `X-Request-ID` without discarding existing preview. UI test verifies unconsumed-column disclosure and escaping of a script-shaped header.
- PHPStan: zero errors. Pint and `git diff --check`: pass. Final Vite production build: pass. Initial sandbox build could not write its temporary configuration file; the authorized standard local build succeeded after escalation, without dependency changes.

Database work uses the dedicated test schema `nordipass_testing`. No supplied workbooks were edited and no normal database writes or production deployment occurred. No new supplier-provenance or staging evidence is claimed. This focused verification does not replace the qualified earlier complete-suite result.

## Evidence and remaining order

Logs reside in the commercial acceptance artifact directory documented in the companion acceptance:

- `source-metadata-focused.log`, `source-metadata-regressions.log`.
- `source-metadata-analysis.log`, `source-metadata-lint.log`.
- `source-metadata-build.log` (sandbox failure), `source-metadata-build-final.log` (successful build).

Remaining gates include authenticated supplier/category/profile acceptance, actual staging workers and recovery, backup/restore/deployment hooks and the separately recorded development dependency advisory. Generated fixtures can extend local profile coverage but cannot establish supplier provenance or target runtime acceptance.
