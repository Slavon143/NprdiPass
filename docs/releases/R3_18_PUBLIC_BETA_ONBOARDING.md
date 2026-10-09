# R3.18 Public Beta Onboarding

R3.18 closes the commercial journey for the Public Beta: a guided onboarding path that walks a company through billing activation, isolated sample data, the first product, the first Passport draft, readiness review, first publication, QR verification, and the help/support path, with entitlement-aware steps and documented recovery paths.

## Implemented

- Added `PublicBetaOnboardingService` with a tenant-scoped snapshot of the launch path:
  - billing, sample data, first product, Passport draft, readiness, publication, QR, and support steps;
  - product/Passport/published counts scoped to the current company via `forCompany`;
  - step completion derived only from current-company data.
- Publication and QR steps are entitlement-aware:
  - they complete only when `public_passports.enabled` is enabled and a published Passport exists;
  - without the entitlement they carry `entitlement_required` and route to billing instead of publication/QR pages.
- Billing step reports subscription status and appends a recovery hint for stalled subscriptions (for example `past_due`).
- Added `CreateSampleProductAction`:
  - creates one idempotent, tenant-scoped sample product (`r3-18-sample-product`) through `CreateProductAction`;
  - reuses the existing product when already present;
  - inherits catalog-create authorization and product-limit entitlements from `CreateProductAction`.
- Added `OnboardingController` with:
  - `GET /onboarding` — launch path dashboard (`CompanyView` permission);
  - `GET /onboarding/help` — journey, readiness, publication, billing, and recovery explanations;
  - `GET /onboarding/support` — support contact and structured recovery paths;
  - `POST /onboarding/sample-product` — sample product creation (catalog-create authorization).
- Added onboarding views (`onboarding/index`, `onboarding/help`, `onboarding/support`) following the existing layout and accessibility conventions, including a plan-required banner and per-step badges when the public-passports entitlement is missing.
- Added a Public Beta / Onboarding entry to the sidebar navigation and an onboarding banner to the dashboard.

## Security and isolation

- Tenant context comes only from `CurrentCompany`; no request-supplied tenant identifiers are trusted.
- All counts and step data are loaded with `forCompany` scopes; a foreign tenant's products, Passports, or publications never affect the snapshot.
- The sample product is per-company and idempotent; cross-tenant creation is rejected by product-create authorization and slug scoping.
- Viewers can read onboarding, help, and support but cannot create sample data (`CatalogCreate` required).
- Entitlement checks reuse `EntitlementService` server-side; UI badges are informational only and never gate behavior.

## Deferred to later stages

- B2B/reseller portal remains R4+.
- No destructive tenant sharing or cross-tenant sample seeding is introduced; onboarding writes are limited to the current company.

## Verification

- `php artisan test tests/Feature/Onboarding`
- `php artisan test`
- `vendor\bin\pint --test`
- `composer analyse`
- `npm run build`
