# Release safety follow-up — 2026-10-11

Release preparation continued under the user's prohibition on paid subscriptions.
No license order, purchase, provider upgrade, new OAuth, token grant/renewal,
Support message, production deployment or push was performed. The existing
protected staging application remained active throughout these checks, without
a writer pause. This supplements the [staging acceptance report](2026_10_11_STAGING_RELEASE_ACCEPTANCE.md).

## Integration restrictions survive the release boundary

The earlier `3e6ae56ba20585b9fd17a82e1b94967e39467f02` archive predates the full
Fortnox guard. A valid archive checksum and compatible schema alone would not
prevent its activation from removing the account restriction. That is an unsafe
historical recovery target for staging.

Deployment and rollback tooling now require a fifth executable site hook,
`--verify-release ROOT RELEASE_DIR`. The staging implementation checks 12 reviewed
Fortnox source fingerprints from `fab8ee1ea47fd8f52b39884a3149b826c0dcb51e`, then
checks actual candidate configuration with pure guard methods. It requires
staging, expected test tenant `1854146`, disabled new OAuth and rejection of
`1853390`, unknown and unrelated tenants. It does not request a token, redirect
to Fortnox, read articles or alter a connection.

Rollback executes this policy before imposing downtime and repeats it under the
deployment lock. Deployment checks it before migrations and activation. Missing
hook configuration fails closed. Future changes to this safety layer require
explicit review and new fingerprints; version labels are insufficient.

Actual target acceptance at 07:25:46 UTC confirmed:

- The current protected release passed.
- The historical `3e6ae56` source and its rollback dry-run were rejected before
  pause and before historical application bootstrap.
- The protected recovery directory passed the rollback dry-run.
- Current remained `staging-20261010-fab8ee1ea47f`, writers remained resumed and
  `/up` and `/ready` were HTTP 200.

At 07:30:43 UTC, the four tested policy files were installed additively in
`/home/codecraf/nordipass-staging/shared/deploy-hooks/`. Existing writer hooks,
HTTP gate, `.env`, application source and connection settings were not replaced.
The installed hook again accepted current and rejected the historical candidate.
The new generic scripts are retained in the versioned tested toolkit:
`shared/acceptance/release-safety-20261011-v8/deploy/scripts/`.
Use them with the installed `verify-release` hook for the next staging operation;
historical retained scripts do not gain this new option automatically.

This is actual rejection and read-only compatibility acceptance, not activation
of the older unsafe application. The previously completed same-artifact
failure/recovery rehearsal remains separately dated evidence. The currently
served protected artifact is the suitable prior baseline for the next candidate;
no older application with different behavior and the required guard has been
qualified. Production needs its own reviewed site policy; the staging hook
intentionally rejects other roots. Workflow source now requires
`DEPLOY_VERIFY_RELEASE`, but no production variable or hook was configured.

## Accurate Node build provenance

The archive generator no longer inserts `node_version: 22.x`. New format-2
archives require an exact observed Node 22 version; missing/wildcard/other-major
values are rejected before artifact creation. Release CI passes the actual
`process.versions.node`. Format-1 archives remain verifiable unchanged for
historical recovery; their wildcard is not treated as measured provenance.

Official portable Node 22.23.3 was downloaded without registration or purchase,
and its ZIP matched the [published SHA-256 list](https://nodejs.org/dist/v22.23.3/SHASUMS256.txt):
`2b0ff57b049cda1bbcea2240eec20467018713c1efe1f7360c2681859b90ed71`.
The system Node installation was not changed. An isolated Git snapshot used
locked npm dependencies with install scripts/funding/audit disabled, then a real
Vite production build. Tailwind's pagination inputs were supplied from the
checksum-verified production Laravel dependency. Compiled runtime views were
absent. The initial build without vendor pagination templates was retained as
intermediate evidence and is not the packaged frontend.

The corrected candidate keeps exact committed application/backend and locked
production vendor bytes from `fab8ee1`. Its fresh frontend output is recorded
separately; CSS differs from the preceding reused build while JavaScript remains
identical. This is not described as a metadata-only asset change. The generated
manifest lists every packaged build file and its checksum.

Candidate archive: `nordipass-20261011-fab8ee1-node22.tar.gz`, 10,066,312 bytes,
SHA-256 `5fdce6f2d5e2455c5d18dc9d596097d06779908a18e70aea4167da9ec64726f9`.
Its format-2 manifest records Node `22.23.3` and PHP packaging provenance `8.4.21`.
Packaging tooling has its own recorded source/package checksum; its new source
changes remain uncommitted. The existing immutable format-1 archive is retained.
The candidate has not replaced current or been deployed to production.

At 07:35:10 UTC, the actual server verified the uploaded archive SHA-256,
retained it by content hash, extracted and verified its complete tree, passed
the installed integration policy and returned schema-check exit 0. No migration
or activation ran. Current stayed on the protected preceding archive, writers
were resumed and both health endpoints returned 200. This isolated target check
does not stand in for the next actual activation/recovery cycle.

## Verification and retained evidence

- **59 native Linux tests, no skips:** 19 archive, 10 lifecycle, 24 writer-hook
  and six release-policy tests. They cover wildcard provenance, historical
  verification, unsafe rollback rejection before pause, unsafe deployment before
  migration, changed/missing guard code and wrong environment/tenant/OAuth flags.
- **12 frontend tests passed on Node 22**, plus the clean-snapshot production
  build. PHP formatting and focused diff checks passed. Application PHP source
  is unchanged, so the prior 2,975-test application result remains dated evidence;
  it was not redundantly rerun for tooling changes.
- Package v8 SHA-256:
  `054b3ed4cb46dc0ddc891495f5a595fca286ed3cc31a6446ad9bde9062494526`.
  Source fingerprints and installed policy hashes are retained in evidence.

The first native run failed because Python-created `/tmp` fixture paths were not
visible to Bash's file checks in this host environment. The same sources passed
after `TMPDIR` was set to a private fixture directory under the accepted staging
test workspace. No application change was made to hide those failures. Windows
sandbox likewise rejected atomic test-directory renames; the same 19 archive
tests passed outside that sandbox on owned temporary files.

Automatic approval review rejected export of the full `tests-private.log` because
its contents had not been established safe. No raw log was downloaded. Diagnosis
used server-side allowlisted test names/error labels; exported evidence contains
only structured counts, fingerprints, known tenant IDs and acceptance results.
Private failed-run logs and archives remain retained.

The [evidence manifest](evidence/2026_10_11_release_safety/manifest.json) links the
observed Node build, safe target results and tested package. The proposed local
commit list excludes all archives, compressed backups, Node binaries, logs,
credentials, caches and unrelated working-tree changes. No additional commit
has been made without the explicit permission required by AGENTS.md.

## Remaining scope

Fixate the reviewed tooling/docs changes, then use the corrected candidate and
qualified protected prior baseline through the normal staging boundary. Actual
activation/rollback of this format-2 candidate is not claimed from its isolated
verification or dry-run. Production deployment remains a separate decision.
Representative high-volume media recovery remains unproven where those stores
were empty in the earlier fixture.

Fresh Fortnox OAuth remains disabled; approved test-client/account settings and
the October 4 license mechanism still need verification before any real flow.
Support's statement proves license addition on that date, not that OAuth buys it.
The Support request remains unsent. Mandatory DPP/operator/Registry and real
battery/sector evidence remain incomplete as described in the staging report.
