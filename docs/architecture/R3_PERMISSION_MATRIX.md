# R3 Permission Matrix Review (R3.16)

Status: R3.16 permission review deliverable. Source of truth: `app/Authorization/CompanyPermissionMatrix.php`, `app/Enums/CompanyPermission.php`, `app/Enums/CompanyRole.php`, enforced via `CompanyAuthorizer` and `CompanyPermissionGate`.

## Roles

| Role | Intent |
|------|--------|
| Owner | Full tenant control incl. members, billing, and Fortnox |
| Admin | Full tenant control (same permission set as Owner) |
| Editor | Operational catalog/passport/document work, no member/billing/Fortnox management |
| Viewer | Read-only access to catalog, documents, and passports |

## Company Permission Matrix

| Permission | Owner/Admin | Editor | Viewer |
|------------|:-----------:|:------:|:------:|
| company.view | Yes | Yes | Yes |
| company.update | Yes | No | No |
| members.view | Yes | Yes | No |
| members.invite | Yes | No | No |
| members.update_role | Yes | No | No |
| members.remove | Yes | No | No |
| audit.view | Yes | No | No |
| api_tokens.view/create/revoke | Yes | No | No |
| catalog.view | Yes | Yes | Yes |
| catalog.create/update/archive/publish | Yes | Yes (create/update) | No |
| catalog.manage_categories/attributes/media | Yes | Yes (media) | No |
| catalog.view_documents | Yes | Yes | Yes |
| catalog.manage_documents / submit_document_review | Yes | Yes | No |
| catalog.review/approve/reject/archive_documents | Yes | No | No |
| passports.view | Yes | Yes | Yes |
| passports.manage | Yes | Yes | No |
| passports.publish | Yes | No | No |
| fortnox.manage | Yes | No | No |
| billing.manage | Yes | No | No |

## Review Findings

1. **Billing and Fortnox are not permission-encoded as entitlements** — `billing.manage`/`fortnox.manage` are permissions; entitlements are enforced separately (contract rule: permissions, entitlements, and feature flags must not substitute for each other). Passed.
2. **Platform permissions are separate from company membership** — `PlatformPermission` (Spatie, guard `web`) never bypasses tenant authorization (`super_admin` does not grant tenant access). Passed.
3. **Maker-checker**: document review/approval and passport publication require `catalog.review_documents`/`catalog.approve_documents` and `passports.publish` respectively — a requester without approve rights cannot self-approve (covered by R3.10 tests). Passed.
4. **Role escalation**: `members.update_role` is Owner/Admin only; the last-owner guard prevents removing the final owner (also enforced by the R3.16 erasure guard). Passed.
5. **Cross-tenant enforcement**: every permission is evaluated against the current company only; the R3.16 cross-tenant sweep (web + API) confirms 403/404 for foreign-tenant resources across catalog, job center, notifications, and API tokens. Passed.

## Residual Items (accepted)

- `Admin` intentionally shares the Owner matrix except the `is_owner` flag used by the last-owner guard; owner-transfer UI is deferred (blocking erasure with a clear message is the R3.16 mitigation).
- Full matrix re-verification against every endpoint remains an R3.19 gate item.

## Verification Commands

- `php artisan test tests/Feature/Security/CrossTenantSweepTest.php`
- `php artisan test tests/Feature/Catalog/CatalogAuthorizationTest.php`
- `php artisan test tests/Feature/Approvals` (R3.10 maker-checker suite)
