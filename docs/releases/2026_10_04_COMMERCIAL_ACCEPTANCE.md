# Commercial v1 — local acceptance, 2026-10-04

Local remediation and verification are complete with the execution-environment qualification below. The initial acceptance left `billing.commercial_enabled` false. After the user's report that the local Billing page still showed Pilot/Growth, the flag was enabled **only in the existing local/test-Stripe environment**. This record does not approve production activation.

## Local storefront activation follow-up

`BILLING_COMMERCIAL_ENABLED=true` is now set in the local `.env`, with a private pre-change environment backup and a cleared configuration cache. The activation helper first verified `APP_ENV=local`, existing test Stripe credentials and all six configured commercial price IDs; it performed no Stripe API calls and changed no subscriptions. Eleven Billing page/interval tests passed (49 assertions). A read-only controller/view rendering check on existing Slavon143 records verified all four plan names and all six displayed prices, no Pilot card, and unchanged subscription rows. The existing 26/1000 legacy allowance remains intact. The application's base layout was retained; the commercial cards show additional prices, allowances and period choices. Evidence: `local-commercial-visible.log/xml` and `local-commercial-runtime-verification.log` in the artifact directory below.

## Completed scope

- Plan and no-card trial policy, legacy compatibility, managed catalogue enrollment, expiry/read-only boundaries, retained memberships and pending invitation seats.
- Monthly operational allowances independent of annual billing, transactional usage deduplication, month/year checkout and visible billing meters.
- Authoritative Stripe projection under company/subscription locks, current subscription binding, reconciliation and preserved trial history.
- Durable storage admission before upload or immutable publication copies; conservative accounting during rollback, ambiguous commit acknowledgements and physical deletion failures.
- Tenant-scoped streaming media scans and bounded relationship queries. Damaged cross-company pointers do not produce another tenant's owner UUID. Storage failures are not reported as missing files.
- Media uploads hold a shared canonical-root filesystem lease through the actual outer transaction; cleanup requires an exclusive lease, current reference reads and resolved write claims. Unknown directories are retained; unsupported remote cleanup is refused.
- Full `product_media(storage_path)` index and read-only release schema verification.
- Cleanup returns a nonzero exit status when its report contains failed deletions or accounting acknowledgements.
- The commercial economics worksheet distinguishes chosen prices and assumed cost ceilings from unmeasured actual margins.

## Verification

The complete standard suite finished in approximately 64 minutes: **2821 tests, 2820 passed, 13203 assertions, one failure, zero errors**. Its single failure was `ReleaseBuildTest`: the subprocess invocation of `python` returned `Access is denied` in this Windows execution environment. Repeating that exact test with `RELEASE_TEST_PYTHON` pointing to the installed Python executable passed: **one Pest test / four assertions**, including all **12 executable Python archive tests**, with no skips. No application or test implementation change was needed. The original complete-suite log is retained as failed; it is not relabeled as a clean full-suite pass.

For future Windows runs, set `RELEASE_TEST_PYTHON` to an installed executable before `composer test`, rather than relying on a generic `python` command. This acceptance used `C:/Users/Vjach/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe`.

The suite was started after the final media lease callback refinement and index installation, before the narrow cleanup exit-status change. That final change has separate passing regression coverage: 19 tests / 32 assertions. The pre-change regression reproduced the false-success exit status. Final PHPStan reports zero errors, Pint passes, and `git diff --check` passes. The frontend build passed after the latest frontend change; subsequent changes are PHP, schema and documentation.

The final media/gallery/index focused checks passed 18 tests / 87 assertions. Eight real-MySQL/process media fence checks passed before the final callback refinement; the complete suite includes the refined implementation.

The normal local database migration was installed only after a private 78-table backup, exact isolated restoration, migration/down/up rehearsal and verification of preserved rows and definitions. The installed schema passes its read-only contract check. Local HTTPS `/up` and `/ready` returned 200 after rollout. No normal business files were cleaned.

## Stripe sandbox and existing CSV evidence

Paid sandbox Starter monthly and annual checkouts succeeded. The two owned test subscriptions were canceled and the four remaining test sessions closed. No live charge occurred.

Four distinct real sandbox Stripe events reached the verified CLI listener and actual Laravel HTTP webhook handler, across the two already canceled acceptance subscriptions. Duplicate delivery returned 200 without increasing processing attempts; an invalid signature returned 400. Three inbox events interrupted by the acceptance receiver's network restriction recovered successfully. Canceled/read-only quota remained zero. Temporary metadata was removed, listener and loopback receiver stopped, and the ephemeral signing secret removed. This is not a persistent staging/production webhook endpoint.

An existing development CSV imported 25 products without failed rows, as unmanaged products. The original helper requested seven mapping targets but checked counts/storage rather than every persisted field. Subsequent supplied-XLSX acceptance established that native SKU and GTIN targets were silently dropped by the old preview validator; only name, slug, description, brand and manufacturer were supported among those targets. The prior result does not establish native identifier persistence. Unsupported targets now return an explicit validation error; see [file acceptance](2026_10_04_FILE_ACCEPTANCE.md). The 4688-byte source was accounted for exactly. This is not a representative supplier XLSX corpus or a throughput benchmark.

Sandbox/CSV fixtures use an owned isolated acceptance database, fake notifications/queues where appropriate, and separate storage. Normal application configuration was not changed by these fixtures.

## Release gates still open

1. Identify and access the real staging deployment; verify proxy, workers, scheduler and deployment rollback there. Available repository examples contain placeholders.
2. Obtain representative supplier XLSX files and accept their full mapping/import behavior on that target.
3. Verify the target webhook/portal configuration and scheduled plan changes. Real sandbox monthly/annual renewal, invoiced upgrade, immediate downgrade, payment failure, grace expiry and recovery now pass through authoritative reconciliation; their exact scope is recorded in [release gates](2026_10_04_RELEASE_GATES.md).
4. Measure the current commercial mapped-product, readiness and retained-document workloads on the target. Current local measurements and their boundaries are recorded in [release gates](2026_10_04_RELEASE_GATES.md); they are not a production SLO.
5. Resolve or explicitly accept the development dependency advisory documented in the dependency reachability report; no published patched `braces` version was available when checked on 2026-10-04.

Before destructive media cleanup, deploy and restart every upload writer. Web, worker and maintenance identities must share access to the lock directory, canonical storage root and compatible filesystem locking. Never unlink an active lock inode. Remote storage and unverified staging mounts require their own validated protocol. Both cleanup commands default to dry-run; bounded scan counters are not exhaustive global totals.

No commit, push, production deployment or production commercial activation was performed. The local storefront activation described above is limited to the configured test Stripe environment.

## Evidence location

Local verification artifacts are under `C:/Users/Vjach/.codex/visualizations/2026/10/02/01a0fcce-800d-7791-8088-d84036dd94f2/continuation-20261003/next-pass/staging-acceptance-20261003/release-completion/commercial/`:

- `commercial-final-full.log` and `commercial-final-full.xml` — complete suite, including the recorded Python invocation failure.
- `release-build-final.log` and `release-build-final.xml` — successful rerun of the sole failed case with an explicit installed Python executable.
- `unit33-after.xml` — final exit-status regression and command coverage.
- `commercial-final-analysis.log`, `commercial-final-lint.log` — final static checks.
- `commercial-final-source-manifest.json` — source baseline when the suite started; `commercial-final-source-manifest-after-unit33.json` records the final command/test delta. Only the cleanup command changed among the fourteen baseline files.
- `media-index-local-*.log` — backup, isolated restore/rehearsal, installation, schema and service recovery.
- `stripe-cli-forward-acceptance.json` — sanitized real HTTP delivery evidence.
- `csv-found-acceptance.json` — persisted import verification, explicitly not timing evidence.

Private backups and acceptance state files contain operational data and must not be published wholesale.
