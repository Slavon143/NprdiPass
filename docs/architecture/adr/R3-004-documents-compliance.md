# ADR-R3-004 — Documents and Compliance Workflow

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None (extends R2 documents model)

---

## Context

R2 has `product_documents` and `product_document_versions` with basic metadata (type, title, visibility, issuer, dates) and immutable version storage. R3 adds compliance workflow: certificate metadata, declarations of conformity, review/approval, expiration tracking, and public visibility control.

## Decision

### Document Type Model
R2 document types are extended with compliance-specific metadata:

```php
// app/Enums/Documents/ProductDocumentType.php
enum ProductDocumentType: string {
    case Instruction = 'instruction';
    case DeclarationOfConformity = 'declaration_of_conformity'; // R3: enhanced
    case Certificate = 'certificate';                           // R3: enhanced
    case SafetyDataSheet = 'safety_data_sheet';
    case Warranty = 'warranty';
    case TechnicalDataSheet = 'technical_data_sheet';
    case RecyclingGuide = 'recycling_guide';
    case Other = 'other';
}
```

### Certificate as Document Subtype
Certificates are NOT a separate entity. A document with `type = certificate` has additional metadata:
- Certificate standard/reference
- Certifying body name
- Certificate number
- Scope description

These are stored as JSON in a new `certificate_metadata` column on `product_document_versions`.

### Review and Approval
- Two new company roles: `Compliance Manager`, `Publisher` (R3.10)
- Approval is a state on the document (`status`: active → pending_review → approved → active)
- Review decisions are audit-logged
- Only approved documents can be associated with publications

### Expiration
- `expires_at` on `product_document_versions` drives notification events
- `DocumentExpiring` event fires N days before expiry (configurable: default 30, 14, 7)
- `DocumentExpired` event fires on expiry date
- Expired documents are still visible in published passports (snapshot remains immutable)

### Public Visibility
- `visibility` enum: `internal`, `passport_public`
- `passport_public` documents appear on public passport page for download
- Blob storage for public documents must be accessible without authentication

### Source Deletion and Published Passports
- Published passport assets are immutable copies (R2 design)
- Deleting the source document does NOT remove it from published snapshot
- The asset in `product_passport_assets` with `kind = document` remains accessible

## Alternatives Considered

1. **Separate Certificate entity**: Rejected — certificates share all the same versioning, file storage, and lifecycle concerns as documents. A separate entity would duplicate the versioning and immutability infrastructure.
2. **Auto-withdraw expired certificates from public page**: Rejected — published passport snapshots must be immutable for regulatory compliance. Expired certificates should show their expiration status, not disappear.

## Consequences

- Documents table gains `reviewed_by`, `reviewed_at`, `approved_by`, `approved_at` columns
- Document versions table gains `certificate_metadata` JSON column (nullable)
- New events: `DocumentSubmittedForReview`, `DocumentApproved`, `DocumentRejected`, `DocumentExpiring`, `DocumentExpired`
- Notifications triggered for expiry, review requests, approval decisions
