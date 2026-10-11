# Canonical staging release result — 2026-10-11

The fixed-commit application is active on staging, with writers resumed. Actual
deployment, rollback to the protected preceding release, and return succeeded.
Fresh final acceptance passed at 08:06:08 UTC; runtime evidence was collected at
08:06:43 UTC. No production deployment, push, paid purchase, subscription order,
provider upgrade, Fortnox OAuth/grant/renewal or Support message was performed.

This is the subsequent result of the [safety follow-up](2026_10_11_RELEASE_SAFETY_FOLLOWUP.md)
and [earlier staging acceptance](2026_10_11_STAGING_RELEASE_ACCEPTANCE.md). Their
pre-commit/pre-activation statements describe their earlier observation times.
The [final evidence](evidence/2026_10_11_canonical_release/final-result.json)
contains structured statuses, counts, fingerprints and recovery identifiers;
it contains no raw database rows, HTML, secrets or private logs.

## Source and executable identity

The user-approved local commit was created from exactly the 36 reviewed files:
`4e7c48f4985d2db71f5463987cd9f38b2df6e0f3`, parent
`fab8ee1ea47fd8f52b39884a3149b826c0dcb51e`. Committed LF blob hashes matched the
reviewed list. No unrelated demo deletions, release-plan changes, test output,
runtime archives or private backups were included. No push was performed.

Active release: `staging-20261011-4e7c48f4985d`.
Artifact: `nordipass-20261011-4e7c48f4985d.tar.gz`, **10,066,305 bytes**, SHA-256
`4c14da3491bb2a6a5796709fa44801e1c50812f19e886f39c4eda53e9129e22c`.
The format-2 manifest records the full commit and observed Node **22.23.3**.
PHP packaging provenance is 8.4.21; the actual staging runtime is **8.4.26**.
The 1,064 tracked allowed application inputs match the committed blobs; generated
frontend files match the tested clean Node 22 build. Backend and locked production
vendor bytes match the previously verified application payload. The historical
format-1 archive remains unchanged; its `22.x` field is not measured provenance.

## Actual deployment and recovery

| Operation / completion UTC | Source → target | Result | Fresh backup UUID |
| --- | --- | --- | --- |
| Deploy / 07:50:20 | protected `fab8ee1` → `4e7c48f` | exit 0; resumed | `d670c933-10bf-4f1c-8d30-6bbb020cad69` |
| Rollback / 07:56:59 | `4e7c48f` → protected `fab8ee1` | exit 0; resumed | `6658a7bc-3a34-4188-81de-67ef37c19898` |
| Return / 07:58:59 | protected `fab8ee1` → `4e7c48f` | exit 0; resumed | `7f9a0e1b-04f3-4d5a-9318-8bb32d56a2bf` |

Each operation used a fresh verified writer boundary, native complete backup,
isolated restoration into the explicitly approved `codecraf_np_boundary_20261009`,
real download to the owner's computer, local byte/SHA-256 verification, and return
of the verified compressed files to the same staging server. Receipts from an
earlier pause were not reused. Latest restoration compared **80 tables / 3,453
rows**, triggers and the four persistent stores, preserving source contents.
The working database was not restored and migrations were not reverted.

Automatic approval review initially rejected staging-backup packaging/egress:
the earlier restoration permission was judged insufficiently explicit for
downloading private data. No rejected transfer was executed. Staging was resumed
on the new release while requesting the precise permission. The user then
explicitly approved both staging-backup downloads to their own computer and
returns to their server. A fresh pause/backup was made before continuing.
The unused backup `1362e696-f1b1-434d-8d6f-424a7fd39cd7` remains on the server;
it was not used as the later operation's proof.

This qualifies recovery between these two guarded artifacts. Their PHP backend
and schema are identical; their source/tooling revisions and frontend builds
differ. It does not prove backward compatibility with arbitrary older PHP/schema
versions. The unsafe `3e6ae56` candidate remains rejected before pause/activation.

## Public asset permission correction

The first expanded final check at 08:00:15 UTC found HTTP 404 for the current
manifest/CSS/JS despite a successful login and health endpoints. Inspection
established correct `public/build → ../current/public/build` mapping, correct
asset bytes, but private operator umask left release/public directories `0700`
and asset files `0600`. Owner-run PHP could read them; static serving could not.
Health endpoints alone did not detect this deployment defect.

The additional tooling correction verifies the retained artifact and complete
installed bytes before normalizing the release root to **0711**, public
directories to **0755**, and reviewed public files to **0644**. It validates the
whole public walk before changing modes, rejects unexpected public secrets,
aliases, links and changed executable bytes, and never traverses/chmods runtime
`public/storage`. `.env`, app, cache, shared storage and backups retain their modes.
Both deploy and explicit rollback invoke preparation before activation.

The corrected toolkit passed **64 native Linux tests, no skips** at 08:04:45 UTC:
23 archive/public-permission tests, 11 lifecycle tests, 24 writer tests and six
release-policy tests. This includes actual deploy/rollback fixtures under
`umask 077` and checks that private/shared modes remain unchanged.
Package v10 SHA-256:
`0088bad3bf7036f016d4e0582520a183ea7396486752c99d1bc39bf3ebd6b06b`.

At 08:05:35 UTC, the tested preparation repaired permissions on the two protected
staging releases after checksum and site-policy verification. Each had five
public directories and ten public files. Private modes and all content hashes
remained unchanged; current was not switched. Archives were not rewritten.
The four extra tooling/test source changes remain **uncommitted at this report's
capture**, outside the earlier 36-file commit authorization. The v10 toolkit is
retained at `shared/acceptance/release-safety-20261011-v10/deploy/scripts/`;
use it for subsequent operations rather than the retained pre-correction v8.

## Final checks and release limits

- `/up`, `/ready`, `/login`, the manifest, CSS and JS returned **HTTP 200**.
  Downloaded asset SHA-256 values match the built payload, and login references
  the current CSS/JS. No raw HTML or cookies were exported.
- Schema verification exited 0; all five companies had valid initialized
  capacity counters, with zero missing/drifting rows. Both October 9 migrations
  are applied. This read-only inspection did not reconcile or append audits.
- Scheduler and worker heartbeats were fresh; failed/reserved jobs and scoped
  CLI writers were zero. One pending job was recorded, not reported as zero.
- Staging guard blocks `1853390`, unknown and unrelated tenants; pure validation
  accepts expected test tenant `1854146`. New authorization is disabled and
  connection metadata matches the retained baseline. No provider call was made.
- No live billing key is configured. The Fortnox test-client pin is still not
  approved, so token renewal/new OAuth must not be enabled from this acceptance.
- Earlier **12 Node 22 frontend tests** and the clean build remain relevant;
  the previously passed **2,975 PHP tests / 14,153 assertions** were not rerun for
  these deployment-only changes. Five accepted high development npm findings
  remain recorded; the audit is not represented as completely clean.

Before production: commit/review the additional permission correction, provision
and validate the production-specific five lifecycle hooks and site policy,
verify the production writer/backup/recovery boundary, and obtain explicit
production-deployment authorization. This staging-only policy rejects other roots.
Representative high-volume media recovery remains limited where fixture stores
were empty. This report does not authorize production deployment or billing.

For a real Fortnox connection, first verify the client/account and obtain explicit
authorization for that flow. October 4 license activation's exact time, actor and
mechanism remain unknown; repeated OAuth is not an established cause. The concrete
[Support request](2026_10_09_FORTNOX_SUPPORT_REQUEST.md) remains unsent.

Mandatory DPP readiness still requires a verified economic operator, real sector
and battery evidence, and actual authorized EU Registry access/integration.
There are zero registry-preparation rows. Technical staging acceptance does not
claim that the platform or a test battery fulfills those regulatory requirements.
