# R3.2 Readiness Test Evidence

## Environment

- Date: 2026-07-22
- Branch: `master`
- Baseline commit: `2b2f75c0bb6548a3a5aebfc1bdc5f51ff862ab54`
- PHP CLI: `C:\Users\Vjach\scoop\apps\php\current\php.exe`, PHP 8.5.2
- Node/npm: Node `v24.15.0`, npm `11.12.1`
- Composer: `2.10.2`
- Testing database: MySQL connection `mysql`, database `nordipass_testing`

## Toolchain remediation

| Component | Initial blocker | Remediation | Verification |
|---|---|---|---|
| PHP extensions | `pdo_mysql`, `fileinfo`, `openssl`, `mbstring` missing; later full suite exposed missing `gd` | Enabled existing DLLs in active Scoop CLI `php.ini` without duplicate declarations in the normal project shell | `php -m`; explicit extension checks returned loaded for `pdo_mysql`, `fileinfo`, `openssl`, `mbstring`, `curl`, `sodium`, `gd` |
| npm | PowerShell npm shim resolved to stale global npm package under AppData | Moved stale global npm package aside as a recoverable backup; used official Node install npm | `npm --version`, `npm ci`, `npm run build`, `npm audit --json` |
| npm cache | AppData npm cache had permission/corruption errors | Moved cache aside as recoverable backup and recreated it | `npm ci` completed with 203 packages installed |
| Composer | Global Composer 2.6.6 emitted PHP 8.5 deprecation noise | `composer self-update --2` to Composer 2.10.2 | `composer --version`, `composer validate --strict`, `composer check-platform-reqs`, explicit-PHP `composer audit` |
| Git safety | Git refused repository ownership during Composer/Git checks | Added exact `D:/laravel/nordipass` safe.directory entry | `git status --short` no longer emits dubious ownership error |

## Migration evidence

| Command | Exit | Result |
|---|---:|---|
| `php artisan migrate:status --env=testing` before upgrade | 0 | Existing MySQL testing schema was present and `2026_07_22_000001_expand_readiness_profile_evidence` was pending. |
| `php artisan migrate --env=testing --force` | 0 | R3.2 migration applied as batch 2 without weakening constraints. |
| `php artisan migrate:fresh --env=testing --force` | 0 | Fresh MySQL install passed; R3.2 migration included. |
| `php artisan migrate:status --env=testing` after fresh | 0 | All migrations, including R3.2, reported `Ran`. |

## Profile and historical reproducibility

| Evidence | Result |
|---|---|
| `php artisan nordipass:readiness-profile nordipass-pilot 1 --env=testing` | 66 rules, weights `10/3/1`, algorithm `weighted_ratio` v1, fingerprint `f668cbb32defc4b23420a129970ec9233c8cb330905898ce2206e37583611569`. |
| Cached profile diagnostic after `config:cache`/`route:cache`/`view:cache` | Same v1 fingerprint and rule count. |
| `ReadinessProfilesHistoricalReproducibilityTest` | v1 validation and published evidence remain unchanged after activating a semantic v2 profile; v2 draft/publish uses a distinct fingerprint and weights. |
| `nordipass:readiness-diagnose` test | Valid passport exits 0 and returns profile, fingerprint, score breakdown, counts, and failed-rule metadata; missing UUID exits 1 safely. |

## Automated checks

| Command | Exit | Tests | Assertions | Skipped | Result |
|---|---:|---:|---:|---:|---|
| `php artisan test tests/Feature/Passports/Readiness/ReadinessAcceptanceFixturesTest.php --env=testing` | 0 | 1 | 35 | 0 | Passed |
| `php artisan test tests/Unit/Passports/Readiness --env=testing` | 0 | 45 | 695 | 0 | Passed |
| R3.2 targeted readiness/publication/API slice | 0 | 103 | 894 | 0 | Passed |
| Full suite `php artisan test --env=testing` | 0 | 2079 | 7882 | 1 | Passed |
| GD-dependent QR/media slice | 0 | 116 | 276 | 0 | Passed after enabling GD |

## Quality gates

| Command | Exit | Result |
|---|---:|---|
| PHP lint over changed tracked PHP files | 0 | Passed |
| `vendor\bin\pint --test` | 0 | Passed |
| `vendor\bin\phpstan analyse` | 0 | Passed, 0 errors |
| `composer validate --strict` | 0 | `composer.json` valid |
| `composer check-platform-reqs` | 0 | Platform requirements satisfied |
| `C:\Users\Vjach\scoop\apps\php\current\php.exe C:\composer\composer.phar install --no-interaction --no-progress` | 0 | Nothing to install/update/remove |
| `C:\Users\Vjach\scoop\apps\php\current\php.exe C:\composer\composer.phar audit` | 0 | No security vulnerability advisories |
| `npm ci` | 0 | 203 packages installed; 0 vulnerabilities during install |
| `npm run build` | 0 | Vite production build emitted manifest/CSS/JS |
| `npm audit --json` | 0 | 0 total vulnerabilities |
| `php artisan route:list --env=testing` | 0 | 220 routes listed |
| `php artisan config:cache` / `route:cache` / `view:cache` | 0 | All caches built |
| `git diff --check` | 0 | Passed |

## Browser/manual E2E evidence

Manual browser verification used `http://127.0.0.1:8767` with `APP_ENV=testing`, MySQL database `nordipass_testing`, and file-backed browser sessions. The current Browser surface exposed a 1280px-wide viewport but did not expose viewport resize controls during this run.

| Check | Result |
|---|---|
| Login | Passed with seeded `demo.owner@nordipass.test` account. |
| Company/product navigation | Passed; NordiPass Demo AB, Reflective Safety Vest, and Traffic signals visible after `ReadinessAcceptanceFixtureSeeder`. |
| Desktop viewport `1440×900` | Readiness page loaded; no console warnings/errors; no horizontal overflow. |
| Tablet viewport `768×1024` | Score and points visible; no console warnings/errors; no horizontal overflow. |
| Mobile viewport `375×667` | Score and points visible; no console warnings/errors; no horizontal overflow. |
| Current browser viewport `1280` wide | Reflective Safety Vest and Traffic signals pages loaded; exact score/points visible; technical profile disclosure visible after opening; no console warnings/errors; no horizontal overflow. |
| Counts vs points UX | Passed: page labels “passed rules” separately from “readiness points earned”; Reflective Safety Vest shows `336 earned points / 421 applicable points = 80%`, and Traffic signals diagnostic/browser fixture shows `277 earned points / 421 applicable points = 66%`. |
| Technical details disclosure | Passed after opening: profile, profile version, algorithm, and fingerprint visible. |
| Legal disclaimer | Present: readiness score is not an official EU score, legal certification, or proof of regulatory compliance. |
| Fix links/source/current/fix action | Present on failed rule cards. |

## Acceptance fixture closure

The supplied `92%` / `383/417` Reflective Safety Vest expectation was audited before any fixture changes. It is stale acceptance truth: `docs/release/R2_FINAL_ACCEPTANCE.md` explicitly states that the earlier `92% / 65-rule-derived expectation was stale and is not used as acceptance truth`, and both `docs/release/R2_TRACEABILITY_MATRIX.md` and `docs/architecture/PASSPORT_READINESS.md` carry the current 66-rule weighted contract.

| Fixture | Source | Passed | Failed blockers | Failed warnings | Failed recommendations | N/A | Points | Score | Result |
|---|---|---:|---:|---:|---:|---:|---:|---:|---|
| Reflective Safety Vest | `NordiPassShowcaseSeeder` via `ReadinessAcceptanceFixtureSeeder` | 42 | 3 | 18 | 1 | 2 | 336 / 421 | 80% | Canonical; not publishable by design |
| Traffic signals | `ReadinessAcceptanceFixtureSeeder` | 34 | 8 | 21 | 1 | 2 | 277 / 421 | 66% | Canonical deterministic equivalent; not publishable by design |

`ReadinessAcceptanceFixturesTest` asserts both seeded records, exact weighted failed-point arithmetic by severity, pinned profile metadata, and the stable Traffic fixture shape (`archived`, no default variant, no media). The previous Traffic absence blocker is closed.
