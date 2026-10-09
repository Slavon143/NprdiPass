# Native import identifiers — 2026-10-04

This follow-up closes the native SKU/GTIN/MPN contract gap identified in the preceding file acceptance. It does not complete staging or authenticated supplier acceptance. Original user workbooks were read without modification. Application writes were confined to owned fixtures in `nordipass_files_acceptance_20261004_testing`; regression tests use `nordipass_testing`. No production publication or external delivery was performed.

## Contract and implementation

- `VariantIdentifierNormalizer::normalize` shares ordinary variant type, length, SKU normalization and GTIN checksum rules with import planning and default-variant creation. `VariantAction` delegates to it.
- `ProductImportRowPlanner::planBatch` obtains existing products/default variants and identifier owners in tenant-scoped batches. Existing secondary variants participate in uniqueness checks. `PreviewImportJobAction` detects normalized duplicate identifiers across preview batches, including the 500-row boundary.
- `CreateProductAction` accepts identifiers for the newly created default variant. `ApplyImportJobAction` uses `UpdateProductVariantAction` for mapped identifiers on update. Product, variant, attributes, audit and capacity changes remain within the row savepoint. Database unique constraints protect changes occurring after preview. Import variant updates retain the shared company-lock protocol and ordinary company permissions.
- Update matching remains normalized product slug. Unmapped identifiers remain unchanged; explicitly mapped blanks clear them. GTIN remains text with leading zeros. MPN is not unique. This is default-variant import, not multiple-variant ingestion.
- Mapping targets reach the existing web selector and API. Unknown targets still fail before replacing preview. Status, timestamps and document references remain unsupported.
- `ProductExportService` adds `sku`, `gtin`, `mpn` columns and loads default variants in tenant scope, checking product ownership. XLSX writes identifiers as explicit text cells. CSV keeps formula-injection escaping; formula-prefixed identifiers may gain an apostrophe, so exact raw CSV round-trip is not promised for these values.

See `docs/API.md` and `docs/api/openapi-v1.yaml` for the current contract.

## Executed file acceptance

The supplied `nordipass_import_realistic_5000.xlsx` was imported again with explicit native identifier mapping and core fields. This separate run deliberately did not map decimal weights, to isolate identifier validation from the earlier precision errors.

Independent extraction/checksum calculation found 500 valid GTINs and 4500 invalid GTINs. Actual preview returned exactly 500 valid rows and 4500 `gtin / invalid_identifier` errors. Apply created 500 products/default variants with zero apply skips; all 1500 accepted native identifier cells matched independently extracted source values. Capacity was 500 total / zero managed. The original source was not repaired or rounded.

A separate explicitly synthetic CSV fixture contains 1000 unique SKUs, checksum-valid GTINs with leading zeros and MPNs. Actual preview/apply created 1000 products/default variants. Actual exports were read and reimported in update mode for both CSV and XLSX: each updated 1000 rows, retained 1000 variants and preserved all 3000 identifier values. Independent Python checksum calculation and CSV/openpyxl extraction subsequently verified all 1000 checksums and 3000 identifier cells in each export, including explicit XLSX string cell types. Reapplying the completed job produced no count drift. These results do not establish target throughput or real supplier provenance.

## Verification

Final native identifier and API verification passed 16 tests / 116 assertions. The damaged-pointer fixture initially failed at insertion because current composite foreign keys correctly prevent cross-tenant pointers. The corrected test temporarily suspends FK checking in the test connection to simulate legacy corruption, then verifies export exclusion and invalid preview without foreign writes. Adjacent import/export, reader/writer, product/variant and API tests plus database retry/worker crash recovery passed 200 tests / 1278 assertions; the two runs overlap and must not be added as unique test counts. Final PHPStan reported zero errors; Pint and `git diff --check` passed. This bounded verification does not replace the qualified historical full-suite result in the release gates.

## Local evidence

Artifacts are in the commercial acceptance directory recorded in `2026_10_04_COMMERCIAL_ACCEPTANCE.md`:

- `native-independent-gtin.py`, `native-original-expected.json`.
- `native-acceptance.php`, `native-acceptance-body.txt`, `native-acceptance.log`.
- `native-original-5000-result.json`, `native-roundtrip-1000-result.json`.
- `native-valid-1000.csv`, actual exports `native-roundtrip-1000.csv/.xlsx`.
- `native-roundtrip-independent.py`, `native-roundtrip-independent-result.json`.
- `native-identifiers-focused-api.log`, `native-identifiers-regressions.log`, `native-identifiers-final-focused.log`, `native-identifiers-final-analysis.log`, `native-identifiers-final-lint.log`.

Retained private fixture manifests are not release attachments. Staging workers, recovery, backup/restore, supplier/profile acceptance and the separate development dependency advisory remain open as documented in the release gates.
