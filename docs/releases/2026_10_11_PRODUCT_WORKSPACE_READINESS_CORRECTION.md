# Authenticated Product Workspace and Readiness correction — 11 October 2026

The authenticated product page and its real passport readiness page now follow the supplied prototype's Product Workspace structure. The earlier `89b7bb238a520b87a00ce5d98982018acd8822f3` staging release changed public/demo presentation but left these real views in their previous layout. This was a presentation coverage gap, not missing product data.

## Changed behavior

- Both screens share Product Workspace title, real SKU/category/manufacturer metadata, current draft revision, current company and Product overview / Readiness / Documents navigation.
- Product overview leads with the product information card and actual readiness panel. Manufacturing countries and materials come from the tenant's current draft payload. Missing information remains missing; sample prototype values and scores are not substituted.
- Catalogue activation readiness remains a separate, initially collapsed section below the passport overview. Existing lifecycle controls, attributes, commerce, media, variant, document and activity workflows remain available.
- Readiness uses a score and count panel on the left, actual blocker cards and field-specific remediation links on the right, then warnings, recommendations, passed checks and publication actions. Rule details, profile provenance, regulatory context and the internal-score disclaimer remain available.
- Publication still follows the confirmation route and existing permissions. Blocked drafts have a disabled action; viewers have no publication/editor/import controls. Historical results are identified as stored, hide recalculation/publication actions and require a fresh evaluation before a publication decision.
- No changes to controllers, mutations, database schema, evaluator, billing, OAuth, external integrations or API contracts are included.

## Verification

- Explicit PHP 8.4.21 focused run: **90 tests / 593 assertions passed**, covering real workspace data, draft metadata, permissions, tenant isolation, lifecycle actions, catalogue navigation, regulatory controls, readiness presentation/security and historical results for expired, unmanaged and archived products.
- Added a real-product presentation regression suite and strengthened readiness tests to inspect the actual DTO score/count, layout, active navigation and publication restrictions.
- Final pre-deploy follow-up: **11 readiness tests / 42 assertions passed**, including active/archived document count consistency. The correction remains within the approved 17-file commit scope.
- A separate render capture uses fictional factory data through the actual controllers and Blade templates inside a rolled-back test transaction. It is a local visual fixture, not an authenticated staging/user-account screenshot.
- Browser inspected both rendered screens at desktop and mobile widths. No page-wide horizontal overflow was observed at the mobile width. Product overview / Documents switching worked; inspected console error output was empty.
- Node 22.23.3 frontend build passed. Affected Pint and whitespace checks passed. PHPStan had zero errors earlier in this correction; no application PHP implementation changed afterwards.
- Test execution requires elevated access to the existing local test database/files in this environment; restricted attempts stopped before test bootstrap. No server or external paid service was invoked by these local checks.

## Delivery boundary

These corrections are local and uncommitted at the time of this report. The prior prototype staging release remains active. A new local commit requires explicit approval under `AGENTS.md`; staging packaging must use that resulting committed version and the retained backup/deployment gates. No push or production deployment is authorized. No paid purchase, checkout, subscription activation or Fortnox reauthorization is part of this change.

Reviewed file hashes are listed in `storage/framework/product-workspace-20261011/proposed-workspace-commit.json`. Private backups, temporary visual fixtures, screenshots and unrelated working-tree changes are excluded.
