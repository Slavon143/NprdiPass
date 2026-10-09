# Release preparation continuation — 2026-10-09

The user authorised continuing release preparation, preserving the prohibition
on paid purchases. No push, paid plan change or production deployment has been
performed. This report records verification before source/archive fixation and
distinguishes current evidence from open gates. After the full gate passed,
the user explicitly authorised one local commit of the 563 selected source files
without push; its resulting identity belongs in the executable archive metadata.

## Hosting and staging

The authenticated Oderland support portal now contains an October 9 response to
ticket 274357. Standard cannot run persistent queue workers/Horizon; PHP processes
are killed after 15 minutes. Persistent workers require Premium/Deluxe according
to the provider. Redis contents do not survive Redis/server restarts. No upgrade
was ordered. The response was read without sending a new message or changing
the ticket. Use the [support ticket list](https://www.oderland.se/client-area/tickets)
to view the existing response; private ticket access codes are excluded here.

Pinned-key SSH command execution succeeded again. A subsequent read-only Laravel
preflight at 10:25:28 UTC observed staging, database queue/cache/session drivers,
scheduler heartbeat 10:25:03 and worker heartbeat 10:25:02. Failed jobs were zero;
only one unreserved maintenance job was present, created at 10:25:03. Five other
queues were empty. This proves recent automatic cron activity, not a persistent
worker or drained writers. There were 607 products and five companies. The
installed schema check returned zero.

The active release remains `staging-source-20261007-3b3dd0fb5f00`. It has no
canonical executable-archive marker or prepared site-hook directory. October 9
DPP application-date and registry-ledger migrations are absent. System Python 3
is 3.6.8, and supervisorctl was not found. A subsequent full-path inventory found
existing `/opt/alt/python310/bin/python3` through
`/opt/alt/python313/bin/python3`. Python 3.12.14 at
`/opt/alt/python312/bin/python3` meets the deployment tools' >=3.10 requirement;
prefix `/opt/alt/python312/bin` to PATH for scripts invoking `python3`.

The actual host then passed all 21 archive/lifecycle Python tests (13 archive,
eight lifecycle) with that runtime and real Bash/flock/symlinks. The owned
fixture directory is
`shared/acceptance/release-tooling-20261009-ahj6z33s`; its `result.json` records
exit 0 and `live_application_mutated=false`. Tooling package SHA-256:
`28c8860cfefd621287782059989d42a62dcbd303a8a11daca8e5c3d02be52e2e`.
PHP, HTTP health and site hooks in these tests are controlled fixtures. This
closes the target runtime/tooling subitem, not actual application deployment,
writer drain or rollback acceptance.

Further target readback found PHP 8.4.26, CLI memory limit 256M,
`max_execution_time=0`, MariaDB 11.4.13 and zero visible application database
events. Actual cron entries hold nonblocking `shared/scheduler.lock` and
`shared/worker.lock`; both scripts use `shared/writers-paused`, enter current and
pin `/opt/alt/php84/usr/bin/php`. The worker uses all six queues and
`--stop-when-empty --max-time=50 --timeout=300`. The pause marker was absent.

A bounded owned PHP CLI probe with no database access ran from 11:19:13 UTC
and completed normally after 920.066 seconds. Thus the support statement's
900-second nominal boundary did not terminate this idle process at that point.
It does not establish support for persistent workers, but waiting for the
nominal policy cannot prove writers have stopped. Explicit process/lock/HTTP
drain remains required. The probe has exited; its independent report is retained
in the tooling acceptance directory.

Several SSH attempts closed while the client was waiting for key authentication.
Immediate terminal input in one orchestration succeeded, including the detailed
protocol's accepted public key and completed command. This is consistent with
an authentication waiting limit, but the server's configured cause has not been
read and is not certified. Credentials were not stored in this report or the
project.

## Local verification and candidate preparation

Pint passes after formatting the two locally authored Redis diagnostic scripts.
PHPStan passes with zero errors. Twelve JavaScript regressions and the production
build pass. Composer validation and live audit pass with no advisories or
abandoned packages. Production npm audit is clean. Full npm audit retains five
high findings in the already accepted braces development-tool chain and no
critical/moderate findings. The complete final PHP invocation passed with exit
0: 2934 tests / 14017 assertions, duration 3795720 ms. Its retained log is
`tests-final.log`; the separate interrupted invocation is not passing evidence.

The existing Laravel release-test wrapper had a stale assertion requiring the
literal summary `Ran 12 tests`, while its Python archive module now has 13.
It now parses the count, requires at least the original 12, requires success
and refuses skipped tests. Its focused regression passes (one test, five
assertions). The earlier full-suite invocation was deliberately interrupted
after discovering this harness issue; only the subsequent final invocation
can provide current full-suite evidence. Marketing copy now says “digital
product passports” without making an unverified blanket compliance claim.

A separate candidate input copy contains 1066 selected application/build inputs,
with source fingerprint
`928c05b7311c71fc07a6b47905e0976832342412d81db376c5b7cd3aeb064481`.
It excludes secrets, runtime storage/caches and Git metadata. Production
dependencies were installed into that copy from the existing lockfile with
scripts disabled: 89 production packages, including Predis 3.7.0, without
development dependencies. Platform requirements, package discovery, route cache
and view cache pass. Local PHP is 8.4.21; target PHP is 8.4.26. Source/candidate
input hashes agree. This is uncommitted preparation, not an approved archive:
`git_commit=null` and `approved_release_candidate=false` remain explicit.

Post-commit provenance inspection found an ignored 98304-byte local
`database/database.sqlite` in that initial scratch copy. It is not a committed
source input and was never uploaded. The original preparation manifest remains
historical evidence; the final executable build must exclude this database.
The canonical artifact classifier now excludes SQLite/DB snapshots and their
WAL/SHM/journal sidecars under database, while retaining migration sources.
Regression coverage checks build exclusion and malicious archive insertion
even with a valid recalculated checksum. All 15 archive Python regressions and
the focused PHP wrapper (one test / five assertions) pass. The actual Linux
target also passes all 23 archive/lifecycle tests (15 + eight), exit 0, in
`shared/acceptance/release-tooling-20261009-sqlite-q7ryc8h0`; package SHA-256
`6717ea49e7fe054403f27b567464271762bc0bf6590309a5137e4c88f507d655`.
The successful target run supplies Python 3.12 in PATH and an owned TMPDIR.
Earlier attempts using the system temporary directory failed the executable
hook-path check and remain separately retained; they are not passing evidence.
These fixtures still use fake PHP/HTTP/site hooks and do not accept an actual
application deployment. The correction is included by updating the same
authorised, unpublished local source commit without push.

`proposed-commit-files.json` lists the concrete working-tree source scope: 563
application/test/documentation/configuration files, including accumulated prior
changes. Temporary data, runtime files, generated caches, Python bytecode and
credential file types are excluded. A limited signature check finds no known
private-key/Stripe-key/GitHub-token patterns; this is not a comprehensive secret
audit. The user subsequently explicitly authorised the local source commit.

## Original supplier workload

All 19134 original first-sheet records from Hager/Berker/Elcom were read through
the native XLSX reader. Original SHA-256 hashes remain unchanged. Prepared CSV
copies preserve every original source column, plus labelled manufacturer,
slug and original-row fields. Row 1 is the source title; row 2 supplies headers.
Second worksheets are excluded intentionally. Native CSV counts agree with all
original product counts. An independent OOXML/Python reader compared every MPN,
name and GTIN to the native preparation and found exact equality. There are no
cross-file GTIN collisions. Different language-specific JSON digests are not
treated as evidence of different records; the full record arrays are compared.

Weight units include G/KG and dimension units MM/CM. The native import mapping
retains unconverted source values and separate explicit unit attributes. List
prices are not mapped to sale prices; SKU policy and lifecycle changes are not
invented. Native preview/apply/reupload/replay completed for all 19134 products
in `nordipass_supplier_20261009_testing`, with fake billing/Fortnox, array mail
and separate storage roots. Every product identity and 133938 attribute values
were verified against the prepared source. Completed replay creates no extra
rows. CSV and XLSX exports preserve every identity. CSV is 3130971 bytes,
SHA-256 `16db2a1cc2f04c0dcaf2e683079c5592f377738bbd5dafcfe2765fec3dcb29ca`;
XLSX is 1713565 bytes,
SHA-256 `0bd8b800cb820723477b970dc411c422dc693bac4acb410054e60dfcc2887f2b`.
The normal commercial trial limits were not raised; imported products remain
unmanaged. The new fixture's initially uninitialised managed counter was null,
while actual managed count was zero. Native capacity reconciliation explicitly
initialised that owned test company and then passed. No working tenant or
external provider is modified. This accepts native CSV ingestion of accurately
derived source data; it does not claim direct XLSX ingestion of the source's
title/header layout.

Independent BMEcat reconciliation matches all 405 Elcom XLSX identities exactly
by MPN/GTIN to ETIM-10.0 classification in the original XML. The 24 class codes
are technical category candidates. Eight XML-only products are not merged into
the XLSX workload. Native category preview/apply then assigned and verified all
405 products across 24 ETIM-10.0 categories. This does not establish a delegated
act or legal DPP profile.

An additional owned ELKO EKO03956 document fixture was created and enrolled in
one managed slot. Native document upload preserves the original 124326-byte
PDF, SHA-256
`66449627b2f64d5a842e287aa536f167821d5723bff7ad312a418279c147299f`.
The electrical EU draft retains undetermined Registry applicability and refuses
publication with incomplete evidence. No legal certification or registration
was manufactured.

Native backup and complete restore into
`nordipass_supplier_restore_20261009_testing` and four separate file roots
completed at 10:48:27 UTC. Both databases contain 19135 products/variants,
133938 attribute values, 24 categories, one document/version, one
passport/version and 38800 immutable audit rows. The current schema contract
passes; file hashes and the original PDF agree; capacity is one managed and
19135 total. Main source counts remain unchanged. The SQL payload is 81334709
uncompressed bytes (6857209 compressed); files archive is 822981 bytes.
An additional read-only comparison hashes every row in deterministic primary-key
order: all 80 tables / 231182 rows match, including tables outside the summary
above. All 30 trigger definitions match after only CRLF-to-LF normalization;
the dump changes line endings, not trigger logic. Native schema verification
and capacity reconciliation without repair also pass against the restored
database. The first strict byte comparison detected those line endings; the
subsequent normalization is limited to this confirmed difference.
Production-imports contains four files and product-documents one; catalog-media
and passport-assets are empty. This accepts representative local database
recovery; high-volume media recovery and target MariaDB restoration remain open.
The larger package's transport was subsequently accepted below.

## Prepared representative target recovery

The jailed-shell cPanel `uapi` executable cannot reach its backend. The existing
authenticated hosting login instead opened cPanel through the provider's normal
SSO. cPanel showed 18.25 GB of the existing 30 GB disk allocation used and no
database-count limit; no paid change was made. It created a previously absent,
empty `codecraf_np_supplier_20261009`. A scoped grant form is prepared for the
existing `codecraf_nordipass_staging` user; privileges have not been submitted.
Browser rules require action-time approval for expanding access, so a specific
question is pending. Existing schemas/grants were not changed.

The native backup, expected full row/trigger hashes, expected migration inventory
and guarded native-restore helper form a 7925114-byte transport package, SHA-256
`74e9941ae10dcba7b0baff62c7e1de45e7762185f8f14450db546db8860c2451`.
It was uploaded to a verified new path, checked and privately extracted under
`shared/acceptance/representative-recovery-20261009-a1f3`. A real download back
to `representative-recovery-returned.zip` matches the original size/SHA-256.
This accepts the larger package's round-trip transport. Database restoration
has not started. Preflight found that the original dump's 30 trigger DEFINER
owners belong to the local account, not the cPanel database user. Do not execute
that original package's restore helper on this server or grant global privileges
to accommodate its foreign owners. The original native backup and evidence are
retained unchanged.

A separate, explicitly labelled cross-account recovery derivative replaces only
the 30 structured mysqldump trigger-DDL DEFINER headers. Business rows and trigger
logic are unchanged; newly restored triggers must belong to CURRENT_USER().
The derivative backup UUID is `aa7cfbd4-c455-4548-82aa-48573eb52629`, derived
from original `5f63fd56-9fa8-410e-87f1-f116fad65e4a`. Its compressed SQL SHA-256
is `ea7beecfc9f50e9e35ad91928d52e90a63e26a7849e9f881610c88d13c7e25f2`;
the persistent-files archive is unchanged. A complete native restore into a
third owned local database and separate file roots passes: all 80 tables /
231182 rows, 30 trigger bodies, native schema and counters agree. The derivative
metadata records this ownership adaptation rather than attributing its checksum
to the original backup.

The scoped transport package is 7641890 bytes, SHA-256
`38e0f6f0e5c790967c2798db54fc7577541bf06a0ea1df3641e28e339210517a`.
It was uploaded, checksum-verified and privately extracted into the previously
absent `shared/acceptance/representative-recovery-20261009-scope-a1f3`.
Its guarded helper refuses a nonempty database/existing file roots, targets only
`codecraf_np_supplier_20261009` and four new roots, verifies every row/trigger/store
and unchanged main application counts/current release. It additionally requires
all restored trigger owners to equal the target CURRENT_USER(), using no global
privilege. It uses the installed native restore and an explicitly bundled
expected migration inventory for isolated schema verification. Execution still
awaits the prepared scoped grant approval. This is a recovery fixture, not an
executable application release or target DPP installation.

## Still open

The Registry Help Center was rechecked at Acceptance v1.0.12. Its official
[user guide, September 16 v1.03](https://single-market-economy.ec.europa.eu/document/download/079a45e2-469f-4eec-b1e5-32e8e05d1357_en?filename=dpp_registry_user_guide_for_economic_operators.pdf)
allows natural-person enrolment (pp.14–17), while p.24 describes QES for an
economic operator acting as sole trader. The test environment still requires
valid identity data and signatures/seals (p.58). Page 54 states successful
battery DPP registration is currently unavailable because its semantic content
is under development. Developer-only status was not misrepresented as verified
operator status; no personal identifiers, declaration or new registration were
submitted. This is a dated guide limitation, not an observed live battery
registration attempt.

- Accept real legal profile/evidence coverage for the intended sectors; technical
  ETIM categories and an incomplete electrical draft do not close this gate.
- Rehearse representative recovery on the actual target, including off-host
  transport and the persistent file coverage required for the chosen scope.
- Build/verify the executable archive from the explicitly authorised source
  revision. Commit permission does not authorise push or a production deployment.
- Prepare concrete site hooks, then rehearse actual target application
  update/failed-health/rollback and writer control. Standard's
  cron option is not a claim of persistent process-manager parity.
- Install and accept the October 9 DPP changes through that release boundary.
- Full mandatory sector/battery DPP still needs real evidence, access/dynamic
  data and authorised EU Registry integration. EU Login is not organisation
  verification or accepted registration. These capabilities remain restricted.

Local evidence is under `storage/framework/release-20261009/`; originals are
under `storage/app/supplier-acceptance/public-20261008/`. These directories are
acceptance artifacts, not proposed release payload or secrets repositories.
