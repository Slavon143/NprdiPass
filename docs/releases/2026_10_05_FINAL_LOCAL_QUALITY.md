# Final local quality evidence — 2026-10-05

The complete local PHP suite passes: **2853 tests, 2853 passed, 13391 assertions**, exit 0. Pest reports 3615647 ms (about 60 minutes). This closes the local PHP verification subitem, not target staging or release-candidate acceptance.

## Verified results

| Check | Result |
| --- | --- |
| Complete PHP suite, `composer test` | Passed; 2853 / 2853 tests, 13391 assertions, exit 0. |
| Pint | Passed in the initial quality invocation. |
| PHPStan | Passed, zero errors, in the initial quality invocation. |
| `node --test tests/Js/GlobalSearch.test.mjs` | One test passed, zero failures or skips. |
| `npm run build` | Passed, exit 0. |
| `composer validate --no-check-publish` | Passed after the test-script timeout fix. |
| `composer test -- --help` | Passed, exit 0; the modified script executes and forwards arguments without rerunning the suite. |
| Composer audit | Zero advisories and abandoned dependencies. |
| Production npm audit (`--omit=dev`) | Zero vulnerabilities. |
| Full npm audit | Five high package findings in the braces advisory chain; explicitly accepted by the user for this release. |
| `git diff --check` | Passed; pre-existing CRLF conversion warnings only. |

## Execution qualification and root-cause fix

The initial `composer quality` invocation exited 1: Composer reported that `php artisan test` exceeded its 300-second process timeout. It produced passing Pint/PHPStan results but no final PHP summary. That invocation remains failed in the evidence.

Only the complete PHP suite was retried, with `COMPOSER_PROCESS_TIMEOUT=0` for that process and `RELEASE_TEST_PYTHON` pointing to the installed bundled Python executable. PHP used the pinned local 8.4.26 runtime; the isolated schema was `nordipass_testing`, with fake integration providers. The retry passed and produced the counts above. It also includes the release archive regression that failed to launch generic Python in the historical acceptance run; that older failure is not retroactively relabelled.

The `composer.json` test script now invokes `Composer\Config::disableProcessTimeout` before the unchanged `php artisan test` command. This applies the same Composer timeout behavior to future standard test/quality commands. Composer validation passes; dependency versions, PHP application code and test selection are unchanged. The combined wrapper was not rerun a third time after all its components completed independently. This is a successful component verification, not a claim that the original wrapper returned 0.

## Source identity

The preserved pre-run input fingerprint is `12585e29aa028a503378c776d390ce8d98b9801ddc52d28fd6be6e90ba740edf`. The final fingerprint is `69c8ec950fe7b0479977b759e7515c41c229071d5d2bbf4420c893da22fb81c4`, over the same 1394 files. Only `composer.json` differs, for the timeout fix. No selected application, test or compiled-build input changed or was removed during verification.

These manifests exclude runtime data, bootstrap/Python caches and installed vendor binaries. They do not identify a committed or signed release archive. The executable archive builder requires a full Git commit SHA; the current uncommitted remediation tree cannot truthfully be attributed only to HEAD. No commit, push or deployment was performed.

## Retained evidence

Artifacts are under:

`C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/staging-acceptance-20261003/release-completion/commercial/`

- `release-20261005-quality.log`: original timeout, including passing Pint/PHPStan.
- `release-20261005-tests-retry.log`: successful complete Pest result.
- `release-20261005-build.log` and `release-20261005-js-tests.log`.
- `release-20261005-test-script-help.log`: successful modified Composer script invocation.
- `release-20261005-composer-audit.json`, `release-20261005-npm-production-audit.json`, `release-20261005-npm-audit-final.json`.
- `release-20261005-source-basis-before-timeout-fix.json` and `release-20261005-source-basis.json`.

The initial `release-20261005-npm-audit.json` is a sandbox network/cache failure and is not counted as a completed audit.

## Still open

See [the current release plan](2026_10_05_RELEASE_PLAN.md): target staging acceptance, authenticated supplier/profile acceptance, and a reviewed fixed source revision plus verified release archive. The [accepted development risk](2026_10_05_DEV_DEPENDENCY_RISK.md) remains visible and requires post-release remediation or upstream reassessment. Local success does not certify production throughput, real Fortnox multi-page application or legal sector coverage.
