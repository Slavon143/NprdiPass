# R3.19 Final Acceptance Evidence

Status: prepared as the R3.19 acceptance evidence record against the final verified source state (branch `master`, commit `2826b40 R3_16_ACCEPTED` plus uncommitted R3.17/R3.18 stage work; see `docs/releases/R3_19_FINAL_ACCEPTANCE.md` §14).

## Validation Suite (R3.1 Required Commands)

```text
Command: composer validate
Exit code: 0
Result: ./composer.json is valid

Command: php artisan config:clear / cache:clear / route:clear / view:clear
Exit codes: 0 / 0 / 0 / 0

Command: php artisan migrate:fresh --env=testing
Exit code: 0
Result: 53 migrations, all DONE (MySQL testing database)

Command: php artisan migrate:status --env=testing
Exit code: 0
Result: 53 migrations, all [1] Ran

Command: php artisan test
Exit code: 0
Result: 2357 tests, 2356 passed, 1 skipped / 9561 assertions
Skipped: BackupDetailedTest "database backup creates valid gzip artifact" — "No database artifact in backup" (environment-dependent, pre-existing)

Command: vendor\bin\pint --test
Exit code: 0

Command: vendor\bin\phpstan analyse
Exit code: 0, errors: 0

Command: npm ci
Exit code: 0 (found 0 vulnerabilities)

Command: npm run build
Exit code: 0 (Vite build passed; JS 45.26 kB gzip 16.11 kB)

Command: composer audit
Exit code: 0 — "No security vulnerability advisories found."

Command: npm audit (initial)
Result: 2 vulnerabilities (1 moderate, 1 high) — browserslist <=4.28.6 (transitive dev dependency via autoprefixer)

Command: npm audit fix
Exit code: 0 — lockfile updated to browserslist 4.29.0 / baseline-browser-mapping 2.11.25

Command: npm ci (after remediation) + npm audit
Exit code: 0 — "found 0 vulnerabilities"; installed browserslist 4.29.0

Command: php artisan route:list
Exit code: 0 — 312 routes

Command: php artisan config:cache / route:cache / view:cache
Exit codes: 0 / 0 / 0

Command: git diff --check
Exit code: 0 (CRLF note on pre-existing test_output.txt only)

Command: git status --short
Exit code: 0 — expected R3.17/R3.18/R3.19 files only
```

Note: `composer test` hits Composer's 300-second process timeout in the local Windows shell; the equivalent direct command `php artisan test` completed successfully in the same source state.

## Gate Journey Suites

```text
Command: php artisan test tests\Feature\Billing tests\Feature\Fortnox tests\Feature\Imports tests\Feature\Passports\Publication
Exit code: 0
Tests/assertions: 152 passed / 553 assertions
Coverage: Commercial Gate (billing flow, subscriptions, entitlements), Integration Gate (Fortnox OAuth/import/sync/conflict), import E2E, publication E2E.

Command: php artisan test tests\Feature\Passports\Qr tests\Feature\Passports\Public tests\Feature\Onboarding
Exit code: 0
Tests/assertions: 158 passed / 556 assertions
Coverage: Public Journey Gate (QR, stable /p/{publicId} resolver, public Passport v2, onboarding E2E incl. recovery paths).

Command: php artisan test tests\Feature\Security tests\Feature\Tenancy tests\Feature\Privacy tests\Feature\Passports\Security tests\Feature\Catalog\Security tests\Feature\Documents\Security tests\Feature\Authorization
Exit code: 0
Tests/assertions: 143 passed / 359 assertions
Coverage: Security Gate (cross-tenant sweep, CSP, upload hardening, GDPR, tenant isolation, authorization matrix).

Command: php artisan test tests\Feature\Passports\Schema tests\Feature\Passports\Readiness tests\Feature\Infrastructure tests\Feature\Observability tests\Feature\Passports\Performance
Exit code: 0
Tests/assertions: 281 passed, 1 skipped / 632 assertions
Coverage: Data Gate (schema migrations, readiness v2 upgrade), Operations Gate (backup/restore, scheduler, health), performance/observability.

Command: php artisan test tests\Feature\Passports\Public\PublicPassportPageTest.php tests\Feature\Passports\Public\PublicPassportLifecycleTest.php
Exit code: 0
Tests/assertions: 28 passed / 83 assertions
Coverage: accessibility landmarks (role="banner", aria-label="Passport sections", role="contentinfo"), public lifecycle.
```

## R2 → R3 Upgrade Path

```text
Command: php artisan test tests\Feature\Passports\Schema\ProductPassportMigrationTest.php
Result: passed — R2.2 passport migrations rollback-safe; R1 catalog tables intact.

Command: php artisan test tests\Feature\Passports\Readiness\ReadinessProfileV2UpgradeTest.php
Exit code: 0
Tests/assertions: 1 passed / 4 assertions
Coverage: nordipass-pilot v1 → v2 profile upgrade/fallback.
```

## Operations Signals

```text
Command: php artisan observability:snapshot --json
Exit code: 0
Result: machine-readable SLO snapshot emitted (thresholds, checks, queue, resolver, storage, catalog).
Local observations (dev machine): queue_lag breached (2 stale pending jobs in local dev DB), storage_free breached (6% dev-disk free), failed_jobs ok, public_resolver_latency ok.
Interpretation: environment states, not product defects; the monitoring path itself works (R3.17 contract).
```

## Dependency Remediation Evidence

```text
Initial: npm audit — 2 vulnerabilities in browserslist <=4.28.6 (GHSA-c83g-rgw3-j3cx, GHSA-73wf-gq98-2v4g)
Action:  npm audit fix (lockfile only; package.json unchanged)
Result:  package-lock.json: browserslist 4.28.6 → 4.29.0, baseline-browser-mapping 2.10.43 → 2.11.25,
         dev-only transitive updates (caniuse-lite, electron-to-chromium, node-releases, update-browserslist-db)
Verify:  npm ci → found 0 vulnerabilities; npm audit → 0; npm run build → passed;
         php artisan test → 2357/2356/9561 passed (same result as pre-remediation)
```
