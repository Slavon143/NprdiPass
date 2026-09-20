# R3.5 Taxonomy Governance Test Evidence

## Migration evidence

The MySQL cycle completed successfully:

1. `php artisan migrate:fresh --env=testing --force`
2. `php artisan migrate:rollback --step=1 --env=testing --force`
3. `php artisan migrate --env=testing --force`

The local database was upgraded with `php artisan migrate --force`; it was not recreated or reseeded.

## Focused evidence

| Scope | Result |
| --- | --- |
| Category template foundation | 6 tests, 31 assertions, passed |
| Taxonomy foundation plus existing definition actions | 17 tests, 101 assertions, passed |
| Catalog attributes, taxonomy, and product UI | 57 tests, 330 assertions, passed |
| Attribute OpenAPI, search, and value validation | 34 tests, 198 assertions, passed |
| Existing Attribute API/UI and Passport publication/web | 218 tests, 711 assertions, passed |
| Governance API/UI/OpenAPI focused gate | 27 tests, 136 assertions, passed |
| PHPStan | Passed, 0 errors |
| Pint | Passed |
| Vite production build | Passed |
| Composer audit | 0 advisories |
| npm production audit | 0 vulnerabilities |
| Full PHP suite | 2,168 tests; 2,167 passed; 1 skipped; 8,760 assertions |
| Browser acceptance | Passed; 6 responsive pages and 9 workflow/security checks |

## Covered contracts

- numbered activation, retirement, one-active-version uniqueness, and activated-version immutability;
- cross-tenant assignment rejection at domain and composite-FK layers;
- required/recommended diagnostics without automatic values;
- profile override, category inheritance, system fallback, and fingerprint verification;
- lifecycle replacement compatibility and referenced-option delete guard;
- pending controlled options require explicit approval and are not assignable;
- deprecated existing options remain readable and count as existing completeness;
- legacy rows receive safe defaults;
- category template activation does not mutate a published Passport payload.
- acknowledgement is rejected until every category-required attribute is present;
- category-template, product-taxonomy, deprecation, and approval APIs enforce token ability, company permission, nested resource ownership, and audit logging;
- web governance pages render without horizontal overflow, console errors, failed requests, or HTTP 500 at 375px and 1280px;
- browser workflows create a cloned v2 draft, approve a controlled-vocabulary option, and return 404 for an unknown tenant-scoped taxonomy resource.

The single skipped test remains the environment-owned production backup artifact test; it is unchanged by the taxonomy foundation.

Browser report: `docs/testing/evidence/r3_5_browser_acceptance.json`. Screenshots are stored in `docs/testing/evidence/r3_5_browser_acceptance/`.
