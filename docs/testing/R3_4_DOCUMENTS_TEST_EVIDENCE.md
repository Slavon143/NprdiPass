# R3.4 Documents Test Evidence

## Baseline

- Starting commit: `d97cb68b6c55eedfe693b025a59345c9f6c99071`
- Branch: `master`
- Laravel: `13.19.0`
- PHP CLI: `8.5.2`
- Testing database: MySQL, `nordipass_testing`
- Migration count before R3.4: 37
- Route count before R3.4: 220
- PHP test files before R3.4: 247

## Automated Evidence

| Command | Result |
| --- | --- |
| `php artisan migrate:fresh --env=testing --force` | Passed |
| `php artisan db:seed --class=R34DocumentsComplianceAcceptanceSeeder --env=testing` | Passed; created deterministic R3.4 Documents Compliance Acceptance fixture |
| `vendor\bin\pest tests\Feature\Documents\Schema\ProductDocumentUpgradeCompatibilityTest.php` | 2 tests, 11 assertions, passed |
| repeated `php artisan db:seed --class=R34DocumentsComplianceAcceptanceSeeder --env=testing` | Passed; fixture replay is idempotent |
| `php artisan test tests\Feature\Documents\Workflow\ProductDocumentWorkflowTest.php --env=testing` | 4 tests, 15 assertions, passed |
| `vendor\bin\pest tests\Feature\Documents` | 30 tests, 103 assertions, passed |
| `php artisan test tests\Feature\Passports\Publication --env=testing` | 74 tests, 179 assertions, passed |
| `php artisan test tests\Feature\Passports\Readiness --env=testing` | 33 tests, 121 assertions, passed |
| `php artisan test tests\Feature\Api\V1\Documents\ProductDocumentApiTest.php --env=testing` | 25 tests, 43 assertions, passed |
| `php artisan test tests\Feature\Api\V1\Catalog\OpenApiSpecificationTest.php tests\Feature\Api\V1\Passports\OpenApiSpecificationTest.php tests\Feature\Api\OpenApiDocumentationTest.php --env=testing` | 19 tests, 108 assertions, passed |
| `vendor\bin\pest` | 2118 tests, 8273 assertions, 2117 passed, 1 skipped |
| `vendor\bin\pint --test` | Passed |
| `vendor\bin\phpstan analyse` | Passed |
| `composer validate --strict` | Passed |
| `composer check-platform-reqs` | Passed |
| `node --version` / `npm --version` | Node `v24.15.0`, npm `11.12.1` |
| clean `npm ci` after removing `node_modules` | Passed; 203 packages installed; no `package-lock.json` change observed |
| `npm run build` | Passed |
| `php artisan nordipass:readiness-profile nordipass-pilot 1 --env=testing` | 66 rules, fingerprint `f668cbb32defc4b23420a129970ec9233c8cb330905898ce2206e37583611569` |
| `php artisan route:list` | 228 routes |
| `git diff --check` | Passed |

## Notes

Parallel execution of Passport publication and readiness slices against the same MySQL database caused expected table create/drop collisions. The slices were rerun sequentially and passed.

## Upgrade Compatibility

`ProductDocumentUpgradeCompatibilityTest` covers the R3.3 to R3.4 compatibility contract from the post-migration application perspective:

- legacy-shaped document versions inserted without R3.4-only metadata receive safe default review and approval states;
- unknown certificate metadata remains `null` and is not invented;
- existing file checksum and storage key are preserved;
- the approved-current-version resolver selects the legacy version deterministically for public use;
- a historical published passport payload and checksum remain unchanged after the source document receives a newer version and is archived.

## Backfill Decision

Backfill: Not Applicable as a separate operational command.

Reason: the R3.4 schema migration expands existing version rows in-place with nullable/defaulted fields and one-time deterministic defaults (`safe_display_filename`, validity mirrors, approved timestamps/users). No legacy payload conversion or historical publication rewrite is required. The resolver handles legacy-compatible version rows through approved/default states and `file_available=true`.

## Dependency Audit Gate

The dependency audits were rerun after explicit user approval for dependency metadata disclosure to Packagist and the public npm registry only.

| Command | Exit code | Critical | High | Moderate | Low | Evidence |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| `composer audit --format=json` | 0 | 0 | 0 | 0 | 0 | `docs/testing/evidence/r3_4_composer_audit.parsed.json` |
| `npm audit --json` | 0 | 0 | 0 | 0 | 0 | `docs/testing/evidence/r3_4_npm_audit.parsed.json` |

Audit summary evidence: `docs/testing/evidence/r3_4_dependency_audit_summary.json`.

Production impact: no advisories were found, no production dependency is affected, and no dependency update or `npm audit fix --force` action was required. Raw audit command outputs were retained locally under `docs/testing/evidence/`; the parsed JSON files extract the successful JSON payloads from surrounding tool diagnostics without publishing dependency inventory.

## Browser / Responsive / Accessibility Gate

Browser E2E was repaired and rerun through `npm run acceptance:r3_4`.

Evidence report: `docs/testing/evidence/r3_4_browser_acceptance.json`.

| Check | Result |
| --- | --- |
| Acceptance server | Passed; exact PHP binary `C:\Users\Vjach\scoop\apps\php\current\php.exe`, port `8766`, `/ready` 200, `/login` 200 |
| Browser pages | 18 pages across mobile, tablet, and desktop, 0 failures |
| Workflow steps | 11 upload/review/cancel/approve/reject/publication steps, 0 failures |
| Accessibility smoke | 1 keyboard/focus/form/status semantics check, 0 failures |
| Security smoke | 3 checks, 0 failures |

Screenshots were written to `docs/testing/evidence/r3_4_browser_acceptance/`. The acceptance runner cleans up the PHP server process and server temp files after completion.

## Final Clean Verification

Final verification was run on 2026-07-22 after the dependency audits and browser acceptance repair.

| Command | Exit code | Result |
| --- | ---: | --- |
| `composer validate --strict` | 0 | Passed |
| `composer check-platform-reqs` | 0 | Passed |
| `vendor\bin\pint --test` | 0 | Passed |
| `vendor\bin\phpstan analyse` | 0 | Passed |
| `npm run build` | 0 | Passed |
| `npm run acceptance:r3_4` | 0 | Passed; 18 pages, 11 workflow steps, 1 accessibility check, 3 security checks |
| `vendor\bin\pest` | 0 | 2118 tests, 8273 assertions, 2117 passed, 1 skipped |
| `php artisan route:list --json --env=testing` | 0 | 228 routes |
| `git diff --check` | 0 | Passed |

Final verification summary: `docs/testing/evidence/r3_4_final_verification_summary.json`.

Cleanup verification: port `8766` was not listening after the acceptance run, and `.env.acceptance` is ignored by Git.

## Skipped Test

- File: `tests/Feature/Infrastructure/BackupDetailedTest.php`
- Test: `database backup creates valid gzip artifact`
- Skip condition: `No database artifact in backup`
- R3.4 relevance: infrastructure backup artifact verification only; it does not cover document workflow, upload security, review, publication, public downloads, tenant isolation, or readiness profile behavior.
- Disposition: non-critical for R3.4 acceptance. The dependency audit, browser, responsive, accessibility, MySQL, regression, build, static analysis, and security evidence are green.

## Verdict

`R3_4_ACCEPTED`
