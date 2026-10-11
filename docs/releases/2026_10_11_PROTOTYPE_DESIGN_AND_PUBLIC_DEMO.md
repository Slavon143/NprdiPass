# Prototype design and public demo — 11 October 2026

**Superseded visual interpretation.** The user rejected this initial design transfer. The corrected implementation and its acceptance evidence are documented in [2026_10_11_PROTOTYPE_FIDELITY_CORRECTION.md](2026_10_11_PROTOTYPE_FIDELITY_CORRECTION.md). The checks below describe the earlier state and must not be treated as visual approval of the current candidate.

Status: implemented and verified locally; uncommitted. No push, staging deployment or production deployment was performed for this change. The previously accepted staging archive does not include these changes.

Reference: https://nordipass-prototype.slava143.chatgpt.site/

## Result

The Laravel landing page now uses the prototype's white and green palette, product image, spacious typography, rounded cards and workflow layout. Primary buttons, login and application shells, product/readiness pages and public passport presentation share the same visual language. Existing product media are reused; no external font, image service or paid design service is introduced.

A public `/demo` route demonstrates five screens: catalogue dashboard, product, readiness, sample import and passport. English and Swedish are supported. Every view transition and passport preview preserves the chosen language; changing language reloads and resets the example, as described in the banner.

The demo provides a complete illustrative journey: inspect a blocker, add a sample assessment, review a warning, create a local demo version, preview fixed CSV/Fortnox rows and add them to the example catalogue. It is intended to remain available to prospective users without a shared account or login. Its fictional product rules and documents do not establish ESPR compliance, genuine certification, a battery passport or EU Registry registration.

## Isolation and external accounts

- The two public GET pages use neither a login session nor tenant queries. A signed-in visitor receives the same fictional content. Supplying `company_id` or `tenant_id` does not select a customer or Fortnox account.
- Public showcase routes exclude `StartSession`, `ShareErrorsFromSession` and Laravel 13's `PreventRequestForgery`. The latter otherwise tries to add a CSRF cookie without a session. These exclusions apply only to `/` and `/demo`; working forms retain their existing middleware and CSRF protection. POST `/demo` is rejected with 405.
- Demo state exists only in the current JavaScript page. No local/session storage, cookies, database mutations, provider calls or upload endpoint are used. Reload and Reset clear the example. Returned sample rows are independent copies.
- Sample imports accept only the two bundled datasets, require a preview and cannot add duplicate rows. They never start OAuth or connect an account. Import cards explicitly describe a fixed demo sample.
- Demo publication is disabled until the example blocker is resolved. Creating a demo version does not call the real publication action, public resolver or EU Registry.
- The landing QR now always targets the fictional demo passport. The former root route queried the latest published passport across customer companies to choose its example. That query has been removed.
- Registration and sign-in links remain visibly separate from the demo. No registration, checkout, subscription activation or paid purchase was performed during this work.
- Real Fortnox guards, callbacks, credentials, pins and existing connections were not changed. The settings page adds a read-only display of the trusted current company, environment and configured permitted test account. Rendering it starts no OAuth request.

## Real dashboard corrections

The former average readiness assigned 62% to unpublished passports and 35% to products without a passport. Its monthly improvement figure was also calculated from publication count rather than historical activity. Both have been replaced with actual current-company document and draft-passport counts. Passport status shows actual published, draft and other counts instead of a decorative ratio chart.

Blockers appear before the selected product's percentage and use an amber progress bar. The publication link requires a live evaluation, ready/ready-with-warnings status, zero blockers and the existing publication/manage permission. Stored evaluations and viewer roles cannot expose that CTA. Actual publication authorization and transactional actions remain unchanged. Products without a passport receive a product-completion link instead of a broken preview link.

The dashboard no longer asserts that Fortnox is connected without consulting a connection. Its card opens connection settings and labels that action accurately.

## Verification

All checks below passed:

1. Broad focused PHP run: **146 tests / 645 assertions**. Covered Marketing, CompanyUi, Navigation, passport readiness/public page/language, Fortnox connection/subscription boundaries, registration and authentication.
2. Final focused run after copy and context-display refinements: **17 tests / 108 assertions** across Marketing, DashboardPresentation and FortnoxPresentation. This repeats 16 cases from the broad run and adds one Fortnox display regression.
3. Node 22 JavaScript suite: **16 tests**, including four new demo-state regressions for blocked publication, preview/idempotency, reset/visitor independence and independent sample copies.
4. Vite production build using the existing local Node 22.23.3 runtime.
5. `composer analyse -- --no-progress`: **zero PHPStan/Larastan errors**.
6. Pint `--test` on the affected PHP files; scoped `git diff --check`.

Broad test command:

```text
python storage/framework/prototype-design-20261011/run-focused.py tests/Feature/Marketing tests/Feature/CompanyUi tests/Feature/Navigation tests/Feature/Passports/Web/PassportReadinessWebTest.php tests/Feature/Passports/Public/PublicPassportPageTest.php tests/Feature/Passports/Public/PublicPassportLanguageTest.php tests/Feature/Fortnox/FortnoxConnectionFlowTest.php tests/Feature/Fortnox/FortnoxSubscriptionBoundaryTest.php tests/Feature/Auth/RegistrationTest.php tests/Feature/Auth/AuthenticationTest.php
```

PHP uses `nordipass_testing`, test storage/caches and fake or HTTP-faked providers with stray requests prohibited. This was a focused UI/boundary verification, not a new full release suite. Existing duplicate PHP-extension startup warnings remain environment warnings; there were no failing assertions.

Browser acceptance used `http://127.0.0.1:8127` with fake billing/Fortnox providers, array sessions/cache and enforced CSP. The blocker/fix/version journey, both sample imports, duplicate prevention, navigation state, Reset, reload and Swedish language selection were exercised. No console warnings/errors were reported. Mobile 390×844 views of home, readiness and passport had no horizontal page overflow; the temporary viewport override was reset.

Local screenshots (ignored runtime evidence):

- `storage/framework/prototype-design-20261011/home-desktop-en.jpg`
- `storage/framework/prototype-design-20261011/home-mobile-sv.jpg`
- `storage/framework/prototype-design-20261011/demo-readiness-desktop-en.jpg`

## Remaining release work

These source changes must be included in a newly built candidate and accepted on staging before production rollout. Existing production connections must not be reauthorized for visual acceptance. The public demo needs no external service provisioning or paid subscription. No migrations or new dependencies are required.

The local preview server is bound only to `127.0.0.1:8127` and remains available for review. The original Sites prototype was inspected and left unchanged. Pre-existing deletions under `demo/puppeteer`, release-plan edits and runtime files were not included in this change.
