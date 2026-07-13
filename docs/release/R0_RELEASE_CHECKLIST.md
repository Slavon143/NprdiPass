# NordiPass R0 — Release Checklist

## Pre-release verification

| # | Check | Status | Evidence |
|---|---|---|---|
| 1 | Clean install (`composer install`) | ✓ | Composer validate strict passes |
| 2 | Fresh migration (`migrate:fresh --seed`) | ✓ | 12 migrations, all Ran |
| 3 | Upgrade migration (`migrate`) | ✓ | `migrate:status` shows all Ran |
| 4 | All tests pass | ✓ | 541/541 passed |
| 5 | Pint (code style) | ✓ | PASS |
| 6 | PHPStan (static analysis) | ✓ | 0 errors |
| 7 | Composer audit | ✓ | No vulnerabilities found |
| 8 | Frontend build (`npm run build`) | ✓ | 4 modules, ~1.7s |
| 9 | Node version consistency | ✓ | .nvmrc=22, local 24.15.0 (>=22) |
| 10 | Config cache (`config:cache`) | ✓ | PASS |
| 11 | Route cache (`route:cache`) | ✓ | PASS |
| 12 | View cache (`view:cache`) | ✓ | PASS |
| 13 | Routes valid (`route:list`) | ✓ | 47 routes (7 API) |
| 14 | Scheduler valid (`schedule:list`) | ✓ | 7 tasks, UTC, no duplicates |
| 15 | No failed jobs (`queue:failed`) | ✓ | No failed jobs |
| 16 | Deploy preflight | ✓ | 15/15 checks passed |
| 17 | `git diff --check` | ✓ | Clean |
| 18 | No debug code (`dd`, `dump`, `var_dump`) | ✓ | None in app/ |

## Security verification

| # | Check | Status | Evidence |
|---|---|---|---|
| 19 | No `env()` in app/routes | ✓ | audit passed |
| 20 | No secrets in logs | ✓ | SensitiveDataSanitizer + LoggingRedactionTest |
| 21 | No raw invitation tokens stored | ✓ | token_hash only, $hidden |
| 22 | No raw API tokens stored | ✓ | Sanctum SHA-256 hash, $hidden |
| 23 | No open redirects | ✓ | All routes use fixed `route()` names |
| 24 | CSRF on web mutations | ✓ | POST/PATCH/DELETE + CSRF middleware |
| 25 | Tenant isolation verified | ✓ | TenantIsolationTest, cross-company negative tests |
| 26 | Authorization verified | ✓ | Role/action matrix, policy tests |
| 27 | Invitation race safety | ✓ | DB transaction + row locks + 23000 constraint |
| 28 | Mass assignment protected | ✓ | Actions use explicit property assignment |
| 29 | Secret scanning | ✓ | No credentials in repository |

## Infrastructure verification

| # | Check | Status | Evidence |
|---|---|---|---|
| 30 | Database backup (real) | ✓ | Backup ID: 3aa51e62... (exit 0, SHA-256 verified) |
| 31 | Files backup (real) | ✓ | Backup ID: 545f38d9... (exit 0) |
| 32 | Backup corruption detection | ✓ | Checksum mismatch → exit 4 |
| 33 | Isolated database restore | ✓ | 10/10 checks, fixture records matched |
| 34 | Release artifact created | ✓ | nordipass-34452c64.tar.gz, SHA-256 verified |
| 35 | Deployment rehearsal | ✓ | 10 scenarios (lock, checksum, switch, rollback) |
| 36 | Infrastructure verdict | ✓ | VERIFIED WITH LIMITATIONS |

## Documentation

| # | Check | Status | Evidence |
|---|---|---|---|
| 37 | README up to date | ✓ | R0 status + infrastructure links |
| 38 | Release notes exist | ✓ | docs/release/R0_RELEASE_NOTES.md |
| 39 | Final quality review exist | ✓ | docs/release/R0_FINAL_QUALITY_REVIEW.md |
| 40 | Infrastructure verification exist | ✓ | docs/infrastructure/INFRASTRUCTURE_VERIFICATION.md |
| 41 | CI docs up to date | ✓ | docs/infrastructure/CI_AND_DEPLOYMENT.md |

## Limitations (accepted)

| Limitation | Severity | Reason |
|---|---|---|
| Production Redis not verified | MEDIUM | Redis not installed locally |
| Production SSH not verified | HIGH | No production server |
| Production Supervisor not verified | MEDIUM | No staging/production environment |
| Production cron not verified | MEDIUM | No production server |
| Production deployment not verified | HIGH | No production server |
| Database backup not production offsite | HIGH | LOCAL disk only (offsite requires S3/SFTP) |
| 3 risky tests (pre-existing, infra tests) | LOW | Non-critical, no test assertions in minor infrastructure checks |
| PHP startup warnings (duplicate modules) | LOW | System PHP configuration (Herd + Scoop) |
| CSP not enforced | LOW | Inline scripts in Alpine.js/Tailwind |
| ShellCheck not run | LOW | Not installed on Windows |
