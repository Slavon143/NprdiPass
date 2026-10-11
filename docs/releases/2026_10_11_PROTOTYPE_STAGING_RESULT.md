# Prototype and registration staging result — 11 October 2026

The corrected prototype design, public browser demo and registration entry links are active at https://staging.nordipass.nu.codecrafters.nu/. Deployment completed at **09:51:34 UTC**; fresh server acceptance passed at **09:52:11 UTC**. Staging writers are resumed. Production was not deployed and no push was performed.

## Source and executable

- User explicitly approved one local commit of the 48 files listed in `storage/framework/staging-prototype-20261011/proposed-design-commit.json`, followed by staging deployment without push.
- Commit: `89b7bb238a520b87a00ce5d98982018acd8822f3`; parent: `9fd70f8567b9c52d1f6d9cf7bed12e0a259c00ba`. Committed LF blob hashes and exact file scope matched the reviewed list.
- Active release: `staging-20261011-89b7bb238a52`. Preceding release `staging-20261011-4e7c48f4985d` and its protected executable remain retained.
- Artifact: `nordipass-20261011-89b7bb238a52.tar.gz`, **10,104,649 bytes**, SHA256 `a14b52d5b77c730677ac09864ee6da921002f45cdf9f9e83001384f4898d6770`.
- Application source was copied from committed Git blobs into an isolated build tree. The production vendor tree came from the verified preceding artifact; `composer.lock` is unchanged. Runtime views, environment files, databases, backups, unrelated demo deletions and unrelated working-tree changes were excluded.
- Frontend was rebuilt in the isolated source tree using existing dependencies and Node **22.23.3**. Manifest and delivered CSS/JS hashes match that build. Packaging PHP is **8.4.21**; staging PHP is **8.4.26**. No dependency or migration changes were introduced.

## Deployment and recovery boundary

The existing tested v10 deploy tooling verified the uploaded payload, paused HTTP/scheduler/worker writers, made a fresh complete backup, restored it only into the previously approved disposable `codecraf_np_boundary_20261009`, and checked restoration before activation. The working staging database was not restored.

Backup UUID: `f690be6f-4161-4b2b-839f-abea606e1ed0`; pause generation: `1791712145078139277`. Both compressed backup files were downloaded into the previously approved local `storage/framework/release-20261011/offhost-backups`, checked by bytes and SHA256, and returned to the same staging server. The matching off-host receipt and actual returned bytes passed the deployment hook. The local backup is private runtime evidence and is excluded from this commit and report.

This operation reused the already tested recovery mechanism. It did **not** perform another rollback rehearsal after this UI deployment. The prior artifact is retained for recovery.

The Fortnox presentation file gained trusted company/environment/test-account information. The release-scoped policy at `shared/acceptance/staging-prototype-20261011/payload/verify-release` uses its reviewed fingerprint; every other Fortnox safety fingerprint remains unchanged. Deploy explicitly used this retained hook. Existing shared policy files were not broadened or overwritten. Subsequent deployments or recovery to this new release must use the retained reviewed hook, rather than assuming the old presentation fingerprint accepts it.

## Verification

- Final explicit **PHP 8.4.21** focused run: **150 tests / 674 assertions passed** across Marketing, CompanyUi, Navigation, readiness/public passport, Fortnox boundaries/presentation, registration and authentication. An earlier invocation selected PHP 8.5.2 via Windows executable resolution; the same suite was repeated on the explicit supported PHP 8.4 executable before activation.
- **16 Node 22 JavaScript tests passed**; PHPStan reported **zero errors**; affected Pint and whitespace checks passed.
- Server `/up`, `/ready`, `/`, `/login`, `/register`, EN dashboard demo and SV passport demo returned **200** with expected contents.
- Manifest, CSS and JS returned **200**, their SHA256 values match the isolated build, and login references the current asset names.
- Schema verification passed; all five companies have valid initialized catalogue counters, with zero missing/drifting rows.
- Home and demo responses set no session cookie. Login and registration retain normal session/CSRF handling. Registration was not submitted against staging as part of acceptance; account creation is covered by isolated local tests.
- Browser verified home Sign up, the login Create an account link, actual registration fields and the public demo dashboard. Console errors/warnings were absent in those views. Screenshots are retained locally.
- Fortnox connection metadata fingerprints and OAuth-state counts match the fresh pre-deploy baseline. Production subscription `1853390`, unknown and unrelated accounts remain blocked; the pure guard accepts test subscription `1854146`. New authorization remains disabled. No new OAuth, token renewal, checkout or paid action was initiated by this operation; no live billing key is configured.

## Operational interruptions

SSH authentication initially failed when the encrypted-key prompt waited too long. Prompt handling was corrected and the same supplied key and pinned host fingerprint were used. No credential or server security setting was changed.

Automatic approval review rejected a diagnostic command containing a private-key header read; it was not executed and was replaced by SSH-only diagnostics. Review also rejected downloading the fresh backup into a new local directory. That transfer was not executed; using exactly the previously approved `release-20261011/offhost-backups` destination was accepted. Staging remained paused while completing this required transfer, then resumed successfully.

Local safe evidence is in `storage/framework/staging-prototype-20261011/`: `commit-result.json`, `candidate.json`, `final-acceptance.json`, `staging-home.jpg`, `staging-registration.jpg` and `staging-dashboard.jpg`. Remote operation and acceptance reports remain in the matching private staging acceptance directory. This post-deployment report was written after the authorized 48-file commit and remains uncommitted.
