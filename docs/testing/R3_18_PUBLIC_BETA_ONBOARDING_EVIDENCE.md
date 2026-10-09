# R3.18 Public Beta Onboarding Evidence

Status: prepared as the R3.18 acceptance evidence record. Update command output after each release-candidate run.

## Focused Coverage

```text
Command: php artisan test tests\Feature\Onboarding
Exit code: 0
Tests/assertions: 10 passed / 70 assertions
Coverage:
- public beta onboarding dashboard renders the tenant launch path
- sample product action is tenant scoped and idempotent
- viewer can inspect onboarding but cannot create sample data
- snapshot never counts foreign tenant products or passports
- support page exposes the structured recovery paths
- onboarding help page explains readiness and recovery paths
- viewer can read onboarding help
- publication and qr steps are entitlement aware when the plan lacks public passports
- billing step exposes a recovery hint for stalled subscriptions
- public beta onboarding journey completes end to end (dashboard → onboarding → sample product → Passport draft → readiness → publication → QR → public page → support)
```

## Full Baseline

```text
Command: php artisan test
Exit code: 0
Tests/assertions: 2357 tests, 2356 passed, 1 skipped / 9561 assertions

Command: composer analyse
Exit code: 0, errors: 0

Command: vendor\bin\pint --test app\Actions\Onboarding app\Services\Onboarding app\Http\Controllers\OnboardingController.php tests\Feature\Onboarding routes\web.php
Exit code: 0

Command: npm run build
Exit code: 0
Notes: production CSS contains the new onboarding utility classes (amber banner/badges); verified `bg-amber-600`, `border-amber-200`, `text-amber-800` in the built stylesheet.
```

Note: `composer test` hits Composer's 300-second process timeout in the local Windows shell. The equivalent direct command `php artisan test` completed successfully in the same source state.

## Public Journey Gate Evidence (R3.18 owner)

The Public Journey Gate requires: Onboarding → product/import → Passport → readiness → publish → QR → public page.

- `PublicBetaOnboardingJourneyTest` walks the full journey through the onboarding HTTP routes, Passport domain actions, publication, the QR page, and the stable public URL `/p/{publicId}` with an OK response.
- Published Passport reads use only the published version; the public page render is asserted after first publication.
- Sample data isolation is asserted in `PublicBetaOnboardingTest`: foreign-tenant products/Passports never leak into the snapshot JSON.

## Recovery Path Evidence

- Support page renders structured recovery paths: billing recovery, job-center retry, publication recovery, and company details.
- Billing step detail exposes `Subscription status: past_due — open billing to resume the journey.` for stalled subscriptions.
- Publication/QR steps route to `settings.billing.index` and show a plan-required badge when `public_passports.enabled` is not granted.

## Security and Isolation Notes

- Onboarding, help, and support require the `CompanyView` permission; sample product creation requires catalog-create authorization.
- Snapshot queries are company-scoped via `forCompany`; no request-supplied tenant identifiers are trusted.
- Entitlement gating is server-side through `EntitlementService`; UI badges are informational only.

## Operational Surface

- `GET /onboarding` — launch path
- `GET /onboarding/help` — journey, readiness, publication, billing, and recovery explanations
- `GET /onboarding/support` — support contact and recovery paths
- `POST /onboarding/sample-product` — idempotent tenant-scoped sample product
- Service: `App\Services\Onboarding\PublicBetaOnboardingService`
- Action: `App\Actions\Onboarding\CreateSampleProductAction`
