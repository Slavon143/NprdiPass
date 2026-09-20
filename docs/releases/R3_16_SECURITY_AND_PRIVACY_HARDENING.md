# R3.16 Security and Privacy Hardening

R3.16 closes the R3 Security Gate: GDPR export/erasure/retention workflows, Content-Security-Policy enforcement, consolidated cross-tenant sweep coverage, secret detection in CI, and the security runbooks, DPA, and processor inventory required by the Privacy Gate.

## Implemented

- Added GDPR data export: `POST /profile/export` (password-confirmed, machine-readable JSON download) built by `GdprExportService` covering profile, memberships, invitations sent, notifications, notification preferences, API token metadata (no secrets), jobs, and audit entries where the user acted.
- Added GDPR erasure: `AccountErasureService` anonymizes personal data immediately (email → `erased-<uuid>@erased.invalid`), revokes API tokens, removes memberships/notifications/preferences, soft-deletes the row, and blocks erasure while the user is the last owner of a company.
- Added `privacy:purge` (scheduled daily) hard-deleting erased user rows past `PRIVACY_USER_ERASURE_RETENTION_DAYS` and pruning webhook delivery history past `PRIVACY_WEBHOOK_DELIVERY_RETENTION_DAYS`.
- Added audit events `gdpr.data_export` and `gdpr.erasure` written to the immutable platform audit log.
- Added Content-Security-Policy via `AddCspHeader` middleware: strict policy (`script-src 'self' 'nonce-…'`, `object-src 'none'`, `frame-ancestors 'self'`, `base-uri`, `form-action`), per-request nonce surfaced to Blade through `@cspNonce`, applied to all inline scripts including the public passport JSON-LD block. Enabled via `SECURITY_CSP_ENABLED` (off for local Vite/HMR); `SECURITY_CSP_REPORT_ONLY` for rollout.
- Added secret detection to CI: gitleaks scan on every push/PR, alongside the existing `composer audit`, `npm audit --audit-level=high`, PHPStan, and Pint steps.
- Added consolidated security suites: `tests/Feature/Security/CspHeaderTest.php`, `CrossTenantSweepTest.php` (catalog lifecycle, job center, notifications, API tokens, API resources), `UploadHardeningTest.php` (image and PDF validation negatives), plus `tests/Feature/Privacy/GdprPrivacyTest.php`.
- Added security runbooks, DPA/processor inventory, and the permission matrix review (see below).

## Security boundaries

- Erasure never deletes business records: tenant catalog data and the immutable audit trail are retained; only personal data is anonymized and removed.
- The last-owner guard prevents orphaning a company through account deletion (transfer ownership first).
- CSP policy blocks inline/foreign script execution except nonce-bearing blocks rendered by the server; object/embedding and form exfiltration channels are closed.
- All personal-data endpoints are authenticated and password-confirmed; exports are streamed with `no-store` caching headers.

## Deferred to later stages

- Automated legal certification: R5+ (R3 non-goal).
- Structured PII-safe telemetry and log redaction: R3.17.
- Full permission matrix re-review and security runbook verification: R3.19.

## Verification

- `php artisan test tests\Feature\Privacy\GdprPrivacyTest.php`
- `php artisan test tests\Feature\Security`
- `vendor\bin\pint --test ...`
- `composer analyse`
- `composer test`
