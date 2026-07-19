# R2 test evidence

Acceptance date: 2026-07-19. All database tests used MySQL 8 and `nordipass_testing`; SQLite was not used.

## Final clean sequence

| Order | Command | Exit | Result | Duration / evidence |
|---:|---|---:|---|---|
| 1 | `php artisan optimize:clear --env=testing` | 0 | All caches cleared | final clean setup |
| 2 | `php artisan migrate:fresh --env=testing --force` | 0 | 37 migrations ran | fresh MySQL schema |
| 3 | `php artisan config:clear --env=testing` | 0 | Configuration cache clear | final clean setup |
| 4 | `php artisan test --compact --log-junit storage/framework/r2-final-junit.xml` | 0 | 2,073 tests; 2,072 passed; 1 skipped; 7,794 assertions; 0 failures/errors | 469.176 s test time / 474.6 s command wall time; XML moved to release evidence after the run |
| 5 | `vendor/bin/pint --test` | 0 | Passed | 16.3 s |
| 6 | `vendor/bin/phpstan analyse --no-progress` | 0 | Passed, 0 errors | 37.6 s |
| 7 | `npm run build` | 0 | Vite production build passed | 9.8 s; CSS 58.03 kB, JS 45.26 kB |
| 8 | `composer validate --strict --no-interaction` | 0 | Manifest/lock valid | 6.3 s |
| 9 | `npm audit --offline` | 0 | 0 vulnerabilities | 4.6 s |
| 10 | `composer audit --locked --no-interaction` | 0 | No security vulnerability advisories found | completed online earlier in the same acceptance session |

Composer 2.6.6 emits dependency deprecation notices under PHP 8.5.2. A later repeat inside the restricted sandbox could not reach Packagist, and an escalated repeat inherited a host PHP extension-path mismatch. Neither retry reported an advisory; the successful online audit used the same unchanged lock file and is the authoritative result.

## Browser E2E

Command:

```text
NORDIPASS_APP_URL=http://127.0.0.1:8766
CI=true
node demo/puppeteer/run-demo.js --business-only --headless --keep-data
```

Credentials were provided through environment variables and are not stored in evidence.

| Field | Result |
|---|---|
| Run ID | `DEMO-20260719-104351` |
| Status | passed |
| Steps | 45 passed / 45 total / 0 failed / 0 skipped |
| Created | 2 categories, 7 attributes, 1 product, 3 variants, 4 images, 3 documents |
| DPP | 9 sections filled; 3 documents attached at draft revision 11 |
| Publication | Passed; stable public URL and QR found |
| Public page | Incognito, no authentication, required name/image/description/manufacturer/no-admin/documents all present |
| Direct public document | HTTP 200; `application/pdf`; immutable public cache |
| Audit | Audit page reached |
| Wall time | 113 s |
| Machine evidence | `report.json`, `report.html` |

Two intentionally red diagnostic runs preceded the final pass and exposed false-positive document uploads and missing certificate metadata. Both causes were remediated; only `DEMO-20260719-104351` is final evidence.

## Focused regression evidence

Before the final full run, the remediated areas were independently exercised:

| Scope | Result |
|---|---|
| Catalog/readiness focused set | 160 tests; expected test-data issues corrected; focused rerun passed |
| Public passport + preview | 105 tests, 349 assertions passed |
| Readiness | 40 tests, 69 assertions passed |
| Legacy publication fixture failures | 3 tests, 9 assertions passed after v2 identity payload correction |
| Passport Feature baseline | 484 tests, 1,350 assertions passed |

The final full-suite result supersedes these partial counts.

## Production-like and operational gates

| Command / check | Result |
|---|---|
| `config:cache` | Passed |
| `route:cache` | Passed; 220 routes in final inventory |
| `view:cache` | Passed |
| `event:cache` | Passed |
| `nordipass:deploy-check` | 15 passed / 0 failed |
| `migrate:status --env=testing` | All 37 migrations Ran |
| `schedule:list --env=testing` | 10 scheduled tasks registered |
| `queue:failed --env=testing` | No failed jobs |
| `nordipass:backup --dry-run --env=testing` | MySQL + files configuration valid; post-create verification enabled |
| `nordipass:backup-prune --dry-run --env=testing` | Retention plan computed without deletion |
| `nordipass:backup-verify be6d... --env=testing` | Files checksum OK (2,048 bytes) |
| `git diff --check` | Passed |
| Debug/TODO scan | No debug helpers, TODO, or FIXME; three `Yaml::dump` matches are legitimate test serialization |
| SQLite scan | No SQLite references in application/test configuration scope |

Production-like caches were cleared after verification so the workspace is not left with environment-specific cached configuration.

## Skipped test

The final suite contains one explicitly skipped test. It is reported by JUnit and does not hide a failure/error. There is no `tests/Integration` directory; integration-level behavior is covered by the Feature suites.

## Evidence files

- `docs/release/evidence/R2_FINAL_JUNIT.xml`
- `docs/release/evidence/R2_BROWSER_01_DASHBOARD.png`
- `docs/release/evidence/R2_BROWSER_02_READINESS.png`
- `report.json`
- `report.html`
