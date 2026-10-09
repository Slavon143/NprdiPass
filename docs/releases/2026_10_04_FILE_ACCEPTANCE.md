# Supplied XLSX and PDF acceptance — 2026-10-04

## Outcome and exact scope

User-supplied files were read without modifying the originals. All catalogue/document writes used the owned `nordipass_files_acceptance_20261004_testing` schema and separate storage, with fake queues/notifications/providers and denied stray HTTP. No normal company catalogue or subscription was changed. No publication, external delivery or production deployment ran.

The XLSX content is explicitly production-like/synthetic. This establishes supplied-file acceptance, not independently verified supplier provenance or target-server throughput. The `Expected` worksheet was source material, not agent instructions.

| Case | Input | Accepted / created | Preview invalid | Result |
| --- | --- | --- | --- | --- |
| Original realistic XLSX | 5000 rows, 19 columns | 3961 | 1039 | Every accepted core/attribute value and category checked; invalid weights remain excluded |
| Original edge XLSX, core fields | 25 rows | 23 | 2 | Required name and duplicate slug rejected; Unicode, trim and literal formula-like text preserved |
| Original edge XLSX, configured attributes | 25 rows | 18 | 7 | Additional negative/locale-decimal weight, unavailable country and percent bounds rejected |
| Synthetic PDF workload | 1000 documents | 1100 retained versions | — | Every stored size/checksum and complete ledger checked; 100 versions reviewed by a distinct actor |
| Document-heavy readiness | 100 products | 100 stored validation runs, 6600 rule results | — | All incomplete drafts remain `not_ready`; no publication |

## Confirmed import issue and fix

`PreviewImportJobAction::validateMapping` previously intersected mapping keys with allowed targets and silently discarded unsupported targets. A request containing `sku` or `gtin` could appear successful while not persisting native variant identifiers. This also invalidates the earlier CSV helper's claimed seven-field coverage: that helper requested those keys but asserted only counts/storage, not their persistence. The companion commercial record has been corrected.

The validator now rejects unsupported/unavailable mapping targets before replacing an existing preview. Regressions cover native SKU/GTIN, unavailable attributes, preserved records and zero product writes; the API regression also verifies 422 `validation_error` and `X-Request-ID`. API and OpenAPI descriptions list supported targets.

This small fix prevents silent loss; it does not add native variant import support. The supported pipeline uses `name`, `slug`, `short_description`, `description`, `brand`, `manufacturer`, `primary_category` and active product-compatible `attribute:<code>` definitions.

## Source and round-trip checks

Original source SHA-256:

- Realistic: `6f7d3b11e40544123395ed056e039c06405278e83559677f933e479d9f07d893`, 536597 bytes.
- Edge: `a0954c778108b23e35982f065d273680eebe6bfc722b86ba4792d0fb8771b777`, 9507 bytes.

Realistic mapping used SKU explicitly as the slug source; native variant SKU was not populated. Eight attributes were configured through real actions: country of origin, material, decimal weight, unit, model, decimal battery voltage, fire rating and integer recyclability. Categories were also created through the normal category action. Independent openpyxl extraction supplied expected core/attribute inputs.

The original workbook contains 1039 General-format weights such as `15.050000000000001`. They exceed strict `DECIMAL(20,4)` precision. No automatic rounding was applied. Accepted rows produced 3961 products/variants, managed/total capacity 0/3961, exact source storage charge 536597 bytes and no duplicate writes on repeated apply. Verification checked 47532 core/attribute values, 3961 categories and all 5000 raw-source GTIN strings with leading zeros retained.

The apply checkpoint reached its last chunk at 109.903 s. The initial harness incorrectly expected preview-invalid rows to contribute to apply `processed_rows/skipped_rows`; that assertion failed after writes completed. The failed log is retained. Post-run verification established the actual contract: 3961 processed/created, 1039 preview-invalid, zero apply-skipped. It is not relabeled as a clean timing run.

The edge string `=1+1 should stay text` is XML `t="str"` with a value and no formula element. A preliminary prefix-based count wrongly classified it as a formula; XML inspection and corrected analysis establish zero actual formula cells. The text round-trips unchanged. True formula rejection is covered separately by the streaming-reader regressions. Originals were not sanitized or rewritten.

Native GTIN/MPN, product status, document reference and update timestamp are unmapped. They remain source data and are not applied. Negative weights/countries/percentages are rejected only when their configured attribute definitions supply the relevant type, bounds or option vocabulary. The configured edge run uses weight minimum zero, recyclability 0–100 and an explicit six-country option set. It does not establish native GTIN checksum/uniqueness validation.

## PDF and readiness checks

Three generated PDFs are visibly marked synthetic and explicitly not valid certificates: a three-page manual, a 24-page technical report and a six-page image-heavy file. PDFs were reopened with pypdf and visually checked through Poppler renders. No real certificate or supplier claim was created.

Across 100 products, uploads created 1000 documents and retained 1100 versions, including 100 revisions with their old files preserved. Stored PDF bytes total 258442900; the ledger totals 258979497 including the original XLSX. Every stored file is available and matches its recorded size and checksum. One hundred technical versions are approved by a different actor; creator self-approval attempts were denied.

The first helper stopped after storing one file because it used the wrong model path property. It resumed that owned file without deleting or duplicating it. The later helper completed uploads and recorded readiness but its foreign-tenant assertion incorrectly expected an absent/uninitialized ledger to be zero instead of `NULL`. Those harness failures are preserved. Independent post-run verification checked all files/rows, accounting, approvals and readiness. A repeated foreign upload was denied before any document or balance/object ledger row was created; the original tenant balance stayed unchanged.

The last upload/review/checksum checkpoint was 33.767 s; this is not a clean full workload benchmark. The final independent document-heavy readiness evaluation of 100 products took 3.163 s with a 74 MiB PHP peak. All products remained blocked with the configured default profile; no claim of alternative-profile acceptance is made.

This exercises storage, versions, review and readiness. It does not exercise OCR/text extraction, persistent supervised workers, remote object storage or production throughput.

## Verification and remaining gates

Import regressions: 62 tests / 327 assertions passed before the additional API case. Final API/OpenAPI checks passed: eight tests / 74 assertions, recorded in `files-api-contract.log/xml`. PHPStan reports zero errors; Pint and `git diff --check` pass. This focused verification does not replace the qualified historical full-suite result.

Remaining release work is ordered:

1. Native identifier support was subsequently implemented and accepted separately in [native identifier acceptance](2026_10_04_NATIVE_IDENTIFIER_ACCEPTANCE.md). The results above describe the earlier mapping contract and remain historical evidence.
2. Source timestamp/status/document-reference behavior was subsequently fixed as an explicit contract with UI disclosure and regression coverage; see [source metadata acceptance](2026_10_04_IMPORT_SOURCE_METADATA_ACCEPTANCE.md). These values remain source data and are not applied as lifecycle changes, persistence timestamps or document attachments.
3. Local category/profile selection and frozen evidence were subsequently exercised using independent synthetic profiles; see [DPP profile selection acceptance](2026_10_04_DPP_PROFILE_SELECTION_ACCEPTANCE.md). Authenticated supplier files and real sector/category mappings remain required.
4. Run current workloads on actual staging, including workers/scheduler, persistent webhooks, Fortnox pagination/recovery, backup/restore and site-hook rollback.
5. Resolve the separate unpatched dev dependency advisory or obtain a release risk decision; no production activation is implied.

## Evidence

Artifacts reside in the commercial acceptance directory documented in the companion record:

- `supplied-xlsx-analysis.json/log`, source expectation helper.
- `supplied-xlsx-5000-result.json`, original preview/apply logs and post-run verification.
- `supplied-xlsx-edge-result.json`, `supplied-xlsx-edge-domain-result.json`.
- `pdf-workload-result.json`, original helper and independent verification logs.
- `xlsx-mapping-regression.log/xml`, `files-import-regressions.log/xml`, `files-api-contract.log/xml`.
- `files-final-analysis.log`, `files-final-lint.log`.

Private fixture manifests and storage are retained locally for reproducibility. Do not publish credentials, backups or private state wholesale.
