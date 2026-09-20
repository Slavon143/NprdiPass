# R3.16 Security and Privacy Test Evidence

Status: R3.16 acceptance evidence. All runs against MySQL 8, local Windows environment, Pest.

## GDPR Privacy Suite

```text
Command:  php artisan test tests\Feature\Privacy\GdprPrivacyTest.php
Exit code: 0
Tests/assertions: 5 passed / 28 assertions
Failures: 0
Skipped: 0
Duration: ~19 s
Coverage:
- export requires account password; wrong password yields no audit event
- export contains only the subject's own data (no foreign tenant names)
- erasure blocked for the last company owner
- erasure anonymizes personal data, revokes tokens, removes memberships, writes gdpr.erasure audit
- privacy:purge hard-deletes erased users past retention and prunes old webhook deliveries
```

## Security Headers and CSP Suite

```text
Command:  php artisan test tests\Feature\Security\CspHeaderTest.php
Exit code: 0
Tests/assertions: 5 passed / 23 assertions
Failures: 0
Skipped: 0
Duration: ~19 s
Coverage:
- CSP omitted while disabled; strict directives when enabled
- report-only mode uses Content-Security-Policy-Report-Only
- inline scripts render with a nonce matching the policy header
- baseline headers (nosniff, Referrer-Policy, X-Frame-Options) always present
```

## Cross-Tenant Sweep Suite

```text
Command:  php artisan test tests\Feature\Security\CrossTenantSweepTest.php
Exit code: 0
Tests/assertions: 5 passed / 25 assertions
Failures: 0
Skipped: 0
Coverage:
- catalog product/category/attribute routes return 404 for foreign tenants (read, edit, update, archive)
- job center and notification resources hidden across tenants
- API tokens of one company cannot be revoked by another company
- API token of tenant A cannot read tenant B catalog resources (404 resource_not_found)
- list endpoints never mix tenant rows
```

## Upload Hardening Suite

```text
Command:  php artisan test tests\Feature\Security\UploadHardeningTest.php
Exit code: 0
Tests/assertions: 8 passed / 8 assertions
Failures: 0
Skipped: 0
Coverage:
- image upload rejects content/mime mismatches, SVG, and oversized files; accepts valid PNG
- PDF upload rejects missing PDF header, double extensions, oversized files; accepts valid PDF
```

## Full Security Directory

```text
Command:  php artisan test tests\Feature\Security
Exit code: 0
Tests/assertions: 18 passed / 56 assertions
Failures: 0
Skipped: 0
Duration: ~19 s
```

## Static Analysis and Formatting

```text
Command:  vendor\bin\pint --test <R3.16 files>
Exit code: 0 (after ordered_imports fix applied to AppServiceProvider)
Command:  composer analyse
Exit code: 0, errors: 0
```

## Environment Notes

- PHP 8.4 (module preload warnings emitted by local php.ini, non-blocking).
- CSP disabled by default outside production (`SECURITY_CSP_ENABLED`); tests enable it explicitly.
- Dependency scanning: `composer audit --locked` and `npm audit --omit=dev --audit-level=high` run in CI; gitleaks secret scan added in the same workflow (not executable locally without the GitHub action context).
