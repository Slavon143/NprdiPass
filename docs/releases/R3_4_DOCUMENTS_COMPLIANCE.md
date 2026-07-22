# R3.4 Documents and Compliance

R3.4 implements the document compliance workflow additively on the accepted R3.3 codebase.

## Implemented

- Expanded document type registry with R3.4 compliance types.
- Version-level review and approval state.
- Structured review decision history and tenant audit events.
- Certificate, declaration, environmental evidence, and compliance evidence metadata fields.
- Server-side expiry state.
- Approved-current-version resolver shared by readiness and publication.
- Private source downloads and immutable public Passport asset downloads.
- Variant association table with tenant composite foreign keys.
- Publication bookkeeping for pinned document versions.

## Compatibility

- Existing documents and versions are backfilled as approved.
- Existing source file storage remains unchanged.
- Existing published Passport assets remain immutable.
- `nordipass-pilot` profile version 1 is not changed.

## Verification Snapshot

- `php artisan migrate:fresh --env=testing --force`: passed.
- `php artisan test tests\Feature\Documents --env=testing`: 28 passed.
- `php artisan test tests\Feature\Documents\Workflow\ProductDocumentWorkflowTest.php --env=testing`: 4 passed.
- `php artisan test tests\Feature\Passports\Publication --env=testing`: 74 passed.
- `php artisan test tests\Feature\Passports\Readiness --env=testing`: 33 passed.
- `php artisan test tests\Feature\Api\V1\Documents\ProductDocumentApiTest.php --env=testing`: 25 passed.
