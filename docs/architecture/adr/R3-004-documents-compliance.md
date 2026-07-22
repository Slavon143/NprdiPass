# ADR-R3-004 — Documents and Compliance Workflow

**Status:** ACCEPTED
**Date:** 2026-07-22
**Stage:** R3.4
**Supersedes:** None (extends R2 documents model)

---

## Context

R2/R3.3 has `product_documents` and immutable `product_document_versions` with private PDF storage and immutable Passport asset promotion. R3.4 keeps that source of truth and adds document-scoped compliance workflow: canonical type registry, certificate/declaration/evidence metadata, review/approval decisions, deterministic expiry state, approved-current-version resolution, public/private publication filtering, and variant-safe associations.

## Decision

### Document Type Model
`ProductDocumentType` remains the canonical technical identifier and is expanded additively with R3.4 types such as `general_document`, `manual`, `technical_specification`, `test_report`, `environmental_evidence`, and `compliance_evidence`. `config/documents.php` defines registry metadata: required/allowed metadata, default visibility, expiry support, review/approval requirements, and readiness mappings.

### Certificate as Document Subtype
Certificates, declarations, test reports, and compliance evidence are NOT separate entities. They are document versions with typed metadata columns and optional JSON metadata. This preserves one document source of truth and one immutable file/version model.

### Review and Approval
- No custom role UI or generic workflow builder is introduced.
- Existing company permissions are extended with document review/approval capabilities.
- Version content remains immutable; only review/approval fields may change after creation.
- Every transition writes `product_document_review_decisions` and tenant audit events.
- Self-approval is blocked by default via `documents.creator_self_approval_allowed=false`.

### Expiration
- Server-side expiry state is deterministic: `not_applicable`, `not_yet_valid`, `valid`, `expiring_soon`, `expired`, `unknown`.
- `valid_from` / `valid_until` are preferred, with `issue_date` / `expires_at` preserved for compatibility.
- Published historical snapshots remain immutable; source expiry changes do not rewrite old payloads.

### Public Visibility
- `visibility` enum: `internal`, `passport_public`
- `passport_public` documents appear on public passport page for download
- Public documents are promoted into immutable `passport_assets` records during publication.
- Source storage paths are never public and are removed from the published payload after asset promotion.

### Source Deletion and Published Passports
- Published passport assets are immutable copies (R2 design)
- Deleting the source document does NOT remove it from published snapshot
- The asset in `product_passport_assets` with `kind = document` remains accessible

## Alternatives Considered

1. **Separate Certificate entity**: Rejected — certificates share all the same versioning, file storage, and lifecycle concerns as documents. A separate entity would duplicate the versioning and immutability infrastructure.
2. **Auto-withdraw expired certificates from public page**: Rejected — published passport snapshots must be immutable for regulatory compliance. Expired certificates should show their expiration status, not disappear.

## Consequences

- `product_document_versions` gains review, approval, metadata, validity, and publication bookkeeping fields.
- `product_document_review_decisions` records structured decisions.
- `product_document_variant` records tenant-safe variant associations.
- Readiness and publication use `ProductDocumentCurrentVersionResolver`.
- No R3.14 notification center or broad workflow platform is introduced.
