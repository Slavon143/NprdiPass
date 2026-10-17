# Current release plan — 2026-10-05

This is the current checklist. The six-row table in the 2026-10-04 release gates is historical evidence by subsystem, not six newly outstanding development tasks. Additional ERP connectors in `R3_DELIVERY_PLAN.md` remain strictly after release.

## October 9 current status

Subsequent [canonical executable acceptance](2026_10_09_CANONICAL_ARTIFACT_ACCEPTANCE.md)
fixes source commit `3e6ae56ba20585b9fd17a82e1b94967e39467f02` without push,
verifies its 9989713-byte executable locally and on the target, and accepts the
real download-back copy. Target isolated bootstrap/caches pass; active staging
was not switched. This paragraph is post-build evidence outside the fixed
executable source revision.

See [current continuation and qualifications](2026_10_09_RELEASE_CONTINUATION.md).
Earlier counts, quality results and unresolved items below retain their dated
historical meaning; this section records the latest completed subitems.

| Area | Completed now | Still required |
| --- | --- | --- |
| Supplier workload | Native preview/apply/idempotent replay and CSV/XLSX export for all 19134 original Hager/Berker/Elcom first-sheet records, with independently verified derived CSV inputs; all identities and 133938 attribute values compared. Native ETIM classification accepted for 405 Elcom products / 24 categories. Original ELKO PDF retained and incomplete draft publication correctly refused. | Real legal profile/evidence acceptance for the intended sectors; technical classification and an incomplete draft do not establish ESPR or battery compliance. |
| Representative recovery | Native full local backup/isolated restore of 19135 products; 81.3 MB uncompressed SQL, 38800 audit rows, four import sources and a real document. Every row in 80 tables and 30 trigger definitions compared; native restored schema/counters pass. Larger original package's off-host round trip passes. A separately labelled cross-account trigger-owner derivative also passes full local restore and is checksum-verified on the target. | Prepared scoped database grant awaits action-time approval, then actual target MariaDB restore. Media/passport asset roots were empty, so high-volume asset recovery is not accepted. |
| Hosting/tooling | Provider confirms Standard's nominal 15-minute PHP policy, no persistent workers and nonpersistent Redis; the bounded idle probe actually completed after 920 seconds, so the nominal limit cannot prove drain. Existing minute cron workers remain database-backed. Existing target Python 3.12.14 discovered; after adding database-snapshot exclusion, all 23 actual-Linux archive/lifecycle fixture tests pass without installing software or buying an upgrade. | Actual HTTP/cron/queue writer stop/drain/resume and backup hooks, canonical application update, failed-health and compatible rollback rehearsals. Fixture hooks are not site acceptance. |
| Source/quality | Final full PHP suite passes: 2934 tests / 14017 assertions, exit 0. Pint, PHPStan, 12 JS regressions, production build, Composer validation/audit and production npm audit pass. Explicitly authorised local source commit fixed; canonical executable has 1065 committed/build inputs and 89 locked production packages. Runtime SQLite copy excluded, with focused regressions. Archive, target retention/isolated bootstrap and real off-host download-back verification pass. | Actual canonical application deployment/rollback, target installation of October 9 DPP migrations and final staging acceptance remain open. |

The full gate passed and the user explicitly authorised one local source commit
without push. No paid change, push or production deployment was performed. Full
mandatory DPP capabilities remain restricted while actual sector evidence,
dynamic/access requirements and authorised EU Registry integration are absent.

## Closed local development items

- Native default-variant SKU/GTIN/MPN import, tenant uniqueness, row rollback and CSV/XLSX export/reimport: accepted in `2026_10_04_NATIVE_IDENTIFIER_ACCEPTANCE.md`.
- Source status/timestamp/document-reference contract, unconsumed-column disclosure, lifecycle/document boundaries: accepted in `2026_10_04_IMPORT_SOURCE_METADATA_ACCEPTANCE.md`.
- Independent category/profile requirement execution, frozen draft/evidence invariants and three profile-selection configuration fixes: accepted in `2026_10_04_DPP_PROFILE_SELECTION_ACCEPTANCE.md`.

## Three remaining blocks

Stage 1 was started on 2026-10-07. See [target staging acceptance](2026_10_07_STAGING_ACCEPTANCE.md) and [October 8 continuation](2026_10_08_STAGING_ACCEPTANCE.md) for target evidence. The authorized working-tree snapshot is installed on PHP 8.4/MariaDB, with a target-discovered MariaDB migration fix. HTTPS health/authenticated smoke, tenant API isolation, automatic cron heartbeats, all six queues, isolated database/file recovery, real Stripe sandbox subscription events and outbound retry/missed-dispatch/interrupted-worker recovery have passed their recorded scenarios. Redis/process parity, remaining provider scenarios, representative-size recovery and deployment/rollback acceptance are still open. No full staging gate has been marked passed.

The subsequent October 7 authorized product/import sequence enrolled one synthetic Fortnox fixture, saved its unpublished passport to revision 7 with a test PDF/image/manufacturer identity, and reached ready_with_warnings (86%, zero blockers, 19 warnings). Native preview and protected-path checks passed. A 500-row CSV import used the unchanged queue threshold and actual cron worker, completed two chunks, created 500 unmanaged fixtures and produced a real successful job-status row and in-app notification. That sequence left 504 products/one managed slot. The October 8 workload below now leaves 604/one, with schema and scoped counters verified. These are fixture/workload results, not supplier evidence, a browser purchase or full stage-1 closure.

October 8 continuation: the user authorized the exact off-host archive round trip and 100 named zero-price Fortnox articles. The 32120-byte archive has now completed local/returned checksum verification and a full native restore into a fresh isolated database and four separate roots, with 30 passing checks and unchanged main storage/counts. This closes transport/recovery for that small retained fixture; representative-size recovery remains open. All 100 Fortnox articles now have verified zero prices, native import completed five real HTTP pages, repeat created/updated zero and raised no conflicts, and original articles/products are unchanged. Authenticated browser readback confirms those runs, the unpublished revision-7 passport and protected preview. The user chose the current hosting server and confirmed only ordinary cPanel access, so supported Redis/persistent workers require hosting administration. See [October 8 evidence and prepared hosting request](2026_10_08_STAGING_ACCEPTANCE.md). No full staging gate is closed.

| Block | Concrete remaining work | Current state |
| --- | --- | --- |
| 1. Target staging acceptance | Real workers/scheduler and persistent webhook delivery; representative Fortnox workload; backup/restore, migration/deployment/rollback using actual site hooks. | October 7 installation/schema/six queues, Stripe event scenarios, outbound recovery/deduplication, tenant smoke and small Fortnox interruption/conflict scenarios passed. October 8 adds authenticated draft preview, 100 zero-price Fortnox fixtures with five-page native import/idempotent repeat, and the approved small archive's off-host round trip/full isolated restore. Database-backed cron workers remain provisional. Hosting-admin Redis/process parity, browser payment flow, representative-size recovery and canonical deployment/rollback remain open. No purchases/subscriptions are authorized. |
| 2. Supplier and profile acceptance | Native acceptance of real source files and category/profile mappings; representative retained documents and target readiness workload. | October 8 discovery acquired unchanged official Hager/Berker/Elcom XLSX with 19134 inspected real records, Elcom BMEcat and an ELKO PDF. Public source provenance is recorded; header/sheet handling, native import, catalogue reconciliation, real profile/evidence acceptance and sector coverage remain open. See `2026_10_08_REAL_SUPPLIER_DATA.md`. |
| 3. Release closure | Reviewed, fixed source revision and verified release archive. | Local quality components are complete: 2853 PHP tests / 13391 assertions, one JS test, Pint, PHPStan and production build pass. The user explicitly accepted the documented braces dev-risk for this release on 2026-10-05. Composer and production npm audits are clean; full npm audit retains five high findings from the accepted advisory chain. Candidate/source revision is still open. |

Completed in the current release-closure pass: frontend production build, one JavaScript regression test, Pint, PHPStan, the complete PHP suite (2853 passed / 13391 assertions, exit 0), Composer validation/audit, production npm audit and the user's explicit current-release braces risk decision. See [final local quality evidence](2026_10_05_FINAL_LOCAL_QUALITY.md).

The first current `composer quality` attempt exited 1 because Composer reported its 300-second process timeout for `php artisan test`; no final PHP test summary was produced. Its passing Pint/PHPStan results remain valid, but that invocation is not a passing full gate. The complete PHP suite was retried through `composer test` with process-local `COMPOSER_PROCESS_TIMEOUT=0` and an explicit bundled `RELEASE_TEST_PYTHON` executable; it passed with exit 0. Original and retry logs are retained separately.

The standard `composer test` script now calls `Composer\Config::disableProcessTimeout` before invoking the same `php artisan test` command, so future `composer quality` runs do not depend on a manual Composer timeout override. `composer validate --no-check-publish` passes. The successful retry had the equivalent process-local timeout setting; no application code, dependency version or test selection changed. The combined wrapper was not rerun a third time; its components have independently completed.

`release-20261005-source-basis.json` records hashes for 1394 final working-tree application/test/build inputs (fingerprint `69c8ec950fe7b0479977b759e7515c41c229071d5d2bbf4420c893da22fb81c4`). Comparison against the preserved pre-run snapshot (`12585e29aa028a503378c776d390ce8d98b9801ddc52d28fd6be6e90ba740edf`) shows only `composer.json` changed, for the timeout fix; no inputs were removed. It excludes runtime data, bootstrap caches, Python caches and installed vendor binaries. It is an input snapshot, not a signed release archive or approved candidate; the packaging tool has its own archive integrity checks.

Only the documented braces dev-risk has explicit acceptance. Release-candidate approval and deployment have not been inferred from test results or that decision. This checklist marks completed subitems rather than regenerating a fixed-size list of open tasks.

The executable archive builder requires a full Git commit SHA. The current working tree contains uncommitted remediation changes, so an archive attributed only to HEAD would not identify those tested changes. Candidate closure requires a reviewed, fixed source revision followed by archive build/verification; no commit or push is performed by this acceptance pass.

## Current dependency finding

See [development dependency risk assessment](2026_10_05_DEV_DEPENDENCY_RISK.md). No dependency version or application behavior was changed by this pass. Historical failed-suite qualifications remain intact; the successful current retry has its own evidence.

October 7 follow-up: the staging browser revealed a CSP defect requiring the CSP-compatible Alpine build. Concurrent npm audits of the current lock and original source snapshot also revealed findings beyond the previously accepted braces chain. The shell-quote/concurrently and source-map-js findings were fixed, verified and installed on staging. The current full npm audit has zero critical, five high (the accepted braces chain) and two moderate findings (postcss-selector-parser/postcss-nested); production npm audit has zero. The two moderate findings still need a release-risk decision or remediation. See [target acceptance](2026_10_07_STAGING_ACCEPTANCE.md). Earlier audit/quality results remain dated historical evidence; prior braces acceptance does not cover new findings. No purchases or subscriptions are authorized.

October 8 local release continuation: the two moderate findings above were remediated with the published parser 7.1.6, pinned through an npm override while retaining Tailwind 3.4.19. Clean npm ci, 12 JS regressions and the production build pass; all compiled asset names/hashes are identical to the control build. Current local full audit is zero critical/moderate and five high in the accepted braces chain; production audit remains zero. See [CSS dependency acceptance](2026_10_08_CSS_DEPENDENCY_ACCEPTANCE.md). This closes the local moderate-dependency subitem; target dependency metadata, final source/archive identity and the later comprehensive release gate remain separate. No target configuration, subscription or paid service was changed.
