# R3.10 — Roles and Approval Foundation

**Status:** ACCEPTED — maker-checker foundation closed; broader approval-center UI remains follow-up scope
**Date:** 2026-09-19

## Scope closed

R3.10 separates draft management from public release approval for Product Passports:

1. `passports.publish` is now registered as a first-class company permission.
2. Direct Passport publication now requires `passports.publish`; `passports.manage` alone can edit drafts but cannot release them publicly.
3. Editors can request publication approval for a specific draft version/revision.
4. Owner/admin approvers can approve a pending request and publish through the existing publication pipeline.
5. A requester cannot approve their own publication request.
6. Approval requests are tenant-scoped and bound to company, product, passport, draft version, and expected draft revision.
7. Approval actions write tenant audit events for request and approval.
8. The publish confirmation page now shows either direct publication controls, request-approval controls, or pending approval controls based on company permission.

## Data model

`passport_publication_approvals` stores the maker-checker handoff:

| Field | Purpose |
|-------|---------|
| `company_id`, `product_id`, `passport_id` | Tenant and resource ownership |
| `draft_version_id`, `expected_revision` | Exact draft snapshot/revision requested |
| `status` | `pending` or `approved` |
| `requested_by`, `requested_at` | Maker identity and request time |
| `approved_by`, `approved_at` | Checker identity and approval time |
| `published_version_id` | Publication produced by the approval |

The approval record does not encode billing entitlements. Billing access remains enforced separately by the publication action.

## Stage gates

| Gate | Evidence |
|------|----------|
| Maker-checker separation | Editor cannot call `PublishProductPassport`; editor can request approval |
| Self-approval protection | Same user cannot approve their own request |
| Tenant ownership | Approval action validates company/product/passport ownership |
| Audit trail | `passport.publication_approval.requested` and `.approved` events |
| Permission/entitlement separation | `passports.publish` authorizes approval; billing entitlement remains in `PublishProductPassport` |

## Validation commands

```bash
php artisan migrate --force
php artisan test tests\Unit\Authorization\CompanyPermissionMatrixTest.php tests\Feature\Passports\Publication\PublishGateTest.php
php artisan route:list --path=publication-approvals
vendor\bin\pint --test app\Actions\Approvals app\Authorization\CompanyPermissionGate.php app\Providers\AuthorizationServiceProvider.php app\Actions\Passports\PublishProductPassport.php app\Http\Controllers\Catalog\PassportPublicationController.php app\Http\Controllers\Catalog\ProductPassportController.php app\Http\Controllers\Catalog\PassportVersionController.php app\Enums\Approvals app\Enums\AuditEvent.php app\Models\Approvals database\migrations\2026_09_19_000002_create_passport_publication_approvals_table.php resources\views\passports\publish-confirm.blade.php routes\web.php tests\Feature\Passports\Publication\PublishGateTest.php tests\Unit\Authorization\CompanyPermissionMatrixTest.php
composer analyse
```

## Follow-up scope

1. Approval inbox/listing for owners and admins.
2. Optional reject/cancel states with comments.
3. Notifications when approval is requested or completed.
