# Import source metadata boundary

Decision recorded 2026-10-04 for the existing product CSV/XLSX pipeline. This defines the current release contract rather than expanding import into publication, remote file ingestion or source conflict resolution.

| Source column | Meaning in this importer | Operation for changing application state |
| --- | --- | --- |
| `status` | Unmapped source text. New products start as drafts; updates preserve existing status. An archived product remains immutable. | Dedicated activate/return-to-draft/archive/restore actions with their company permissions, locks, checks and audit. |
| `updated_at` | Unmapped source text, retained with the source row. Not assigned to product timestamps and not used as an ordering watermark. | Product timestamps record application writes. A future source-specific freshness contract needs a separate provenance field and conflict policy. |
| `document_ref` | Unmapped source text. Neither a trusted document identifier nor an instruction to fetch a URL or open a file. | Dedicated document upload, version, review and evidence workflows. Future batch document matching needs tenant ownership, file verification and explicit attachment semantics. |

Explicit mappings to these target names fail validation before persisted preview replacement. Like other headers, these source values may be deliberately mapped into a supported descriptive field or a configured product attribute; that does not confer lifecycle, timestamp or document semantics. Unmapped columns are retained in `ImportRecord::source_data` and the original stored file, subject to ordinary tenant access and retention. API preview rows contain applied `mapped_data`, not raw `source_data`.

No stale-row suppression is claimed: update/upsert apply the saved mapping against the current product by normalized slug, even when an unmapped source timestamp is old. Supplier status vocabularies also need explicit translation before any future lifecycle import can be considered. Passing tests is not evidence of an unimplemented business policy.

The import page explains these boundaries and, after preview, lists source headers not consumed by the saved mapping. Header names are rendered with Blade escaping. Changing form selections requires regenerating preview; the list describes the saved plan, not unsaved selections.

Verification covers create/update status preservation, application timestamps, source retention, zero remote HTTP requests/document writes, archived-product protection, explicit API mapping rejection and escaped unconsumed headers. See the companion release acceptance record.
