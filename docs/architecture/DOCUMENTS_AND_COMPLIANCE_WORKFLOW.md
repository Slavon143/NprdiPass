# Documents and Compliance Workflow

R3.4 extends the existing `ProductDocument` / `ProductDocumentVersion` subsystem. It does not introduce a second certificate store or Passport-only document model.

## Domain Model

- `product_documents` is the stable logical document, scoped by `company_id` and `product_id`.
- `product_document_versions` is the immutable file/metadata version. Content fields are protected by MySQL triggers and Eloquent guards.
- `product_document_review_decisions` records submit, cancel, approve, and reject transitions.
- `product_document_variant` records tenant-safe document-to-variant associations.

## Type Registry

`ProductDocumentType` contains legacy R2 codes and R3.4 codes. `config/documents.php` defines registry metadata: title, required metadata, allowed metadata, default visibility, expiry support, review requirement, approval requirement, and readiness mappings.

## Lifecycle

Version state is document-scoped and intentionally small:

`draft -> pending_review -> approved`

`pending_review -> rejected`

`pending_review -> cancelled`

Rejected or cancelled versions can be resubmitted. Approved versions remain immutable and can be superseded by a newer approved version through the resolver.

## Approval and Audit

Document review is not a generic workflow platform. Actions are implemented by:

- `SubmitProductDocumentVersionForReviewAction`
- `CancelProductDocumentReviewAction`
- `ApproveProductDocumentVersionAction`
- `RejectProductDocumentVersionAction`

Each action writes a structured decision row and a tenant audit event. Rejection requires a reason. Self-approval is blocked by default.

## Expiry

Expiry is server-side and deterministic. `ProductDocumentVersion::expiryState()` returns one of:

`not_applicable`, `not_yet_valid`, `valid`, `expiring_soon`, `expired`, `unknown`.

The expiring-soon threshold is `documents.expiry_warning_days`.

## Current Version Resolution

`ProductDocumentCurrentVersionResolver` selects the latest approved, available, non-expired candidate. Publication requests a public-only candidate. If the newest approved candidate is expired or not-yet-valid, the resolver falls back to an older approved valid version.

## Files and Downloads

Source PDFs are stored on the private `product_documents` disk with tenant/product/document/version paths. Upload validation checks PDF MIME/header, extension, size, unsafe names, double-extension names, checksum, and storage-key safety. Public Passport downloads use immutable `passport_assets`, not source storage paths.

## Publication

Publication pins exact document version UUIDs, promotes document files into immutable Passport assets, removes source storage keys from the published payload, and increments publication bookkeeping on the pinned source version. Later source edits, new versions, rejection, archive, or visibility changes do not mutate historical published versions.

## Readiness

Readiness keeps the accepted `nordipass-pilot` v1 profile. Existing document rules now receive resolver-selected approved/current versions. No profile rules, weights, severities, or fingerprint were intentionally changed.
