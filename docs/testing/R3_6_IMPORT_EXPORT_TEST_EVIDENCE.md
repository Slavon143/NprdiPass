# R3.6 Production Import/Export Test Evidence

## Acceptance status

**Automated gates:** PASSED  
**Responsive browser gate:** PASSED

R3.6 is implemented, automated-test complete, and accepted with browser evidence recorded under `docs/testing/evidence/`.

## Migration evidence

The MySQL testing cycle completed successfully:

1. `php artisan migrate:fresh --env=testing --force`
2. `php artisan migrate:rollback --step=1 --env=testing --force`
3. `php artisan migrate --env=testing --force`

The local database received only the additive R3.6 migration with `php artisan migrate --force`; it was not recreated or reseeded.

## Automated evidence

| Scope | Result |
| --- | --- |
| Focused import/export and API tests | 34 tests, 170 assertions, passed |
| Catalog/API regression gate | 394 tests, 1,480 assertions, passed |
| Full MySQL PHP suite | 2,186 tests; 2,185 passed; 1 skipped; 8,878 assertions |
| PHPStan | Passed, 0 errors |
| Pint | Passed |
| Vite production build | Passed |
| Composer validation | Passed (`--strict`) |
| Composer audit | 0 advisories |
| npm production audit | 0 vulnerabilities |
| Migration fresh/rollback/upgrade | Passed |
| Local additive migration | Passed |
| Browser acceptance | Passed; JSON report and four responsive screenshots recorded |

The full-suite JUnit report is stored at `storage/framework/r3-6-final-junit.xml`. The one skipped test is the existing environment-owned production backup artifact test and is unchanged by R3.6.

## Covered contracts

- CSV and XLSX parsing/export parity, including valid XLSX generation and importer round-trip;
- source-content idempotency scoped by company, mode, and mapping;
- preview/write normalized-plan parity and stale/fingerprint protection;
- create, update, and upsert behavior without clearing unmapped aggregate fields or attributes;
- duplicate slugs and duplicate rows, invalid category and vocabulary options, and row error reporting;
- active/approved controlled-vocabulary enforcement and category resolution by UUID/slug;
- persisted chunk cursor, two-boundary resume, and already-applied row idempotency;
- 1,500-row preview and chunk-boundary behavior on MySQL;
- cross-tenant 404 behavior for jobs/templates and tenant-only exports;
- web authorization, API token abilities, upload hardening, XLSX formula rejection, ZIP limits, and export formula neutralization;
- feature flag disabled behavior outside enabled environments;
- additive OpenAPI parity for list/upload/show/preview/apply, errors, templates, and CSV/XLSX exports.

## Browser evidence

The prepared harness at `storage/framework/r3_6_acceptance.mjs` passed and wrote `docs/testing/evidence/r3_6_browser_acceptance.json`. It exercises:

1. authenticated access to the import workspace;
2. CSV upload and field mapping;
3. preview with a deliberately invalid row and error-report download;
4. mapping-template persistence;
5. two-chunk apply/resume completion;
6. CSV and XLSX export downloads;
7. 375px and 1280px responsive layouts;
8. console errors, failed requests, HTTP 500 responses, and horizontal overflow.

The recorded report has `result: passed`, no console errors, no failed requests, no HTTP 500 responses, and no horizontal overflow at 375px or 1280px for the import index and import job screens. CSV and XLSX exports returned HTTP 200 with non-empty payloads.
