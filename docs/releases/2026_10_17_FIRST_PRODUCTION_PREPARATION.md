# First production pilot preparation — 17 October 2026

Status: production infrastructure prepared in isolation; application not published.
The user selected `nordipass.com` and the existing Oderland account. No paid plan
change, live payment or Fortnox authorization was performed. The user explicitly
authorized a local release commit of the reviewed 162-file scope, without push.

## Target state observed and prepared

- SSH identity `codecraf` on `46.16.236.20`; native PHP **8.4.26**, Python **3.12**.
  Web PHP **8.4.26** and **21 extensions** were independently verified through
  the domain's origin using a temporary IP-restricted probe containing no
  application data. The probe was removed and the original access denial restored.
  cPanel's LangPHP lookup was unavailable (no MultiPHP feature), so its response
  was not treated as runtime evidence.
- Added `nordipass.com` to cPanel, mapped to
  `/home/codecraf/nordipass-production/public`, independently of staging.
- `public -> current/public`, `current -> releases/bootstrap`; the bootstrap
  document root uses `Require all denied`. HTTP origin probes of `/` and `/.env`
  return **403**. The domain has not been switched to application code.
- Created empty database `codecraf_np_production` and new user `codecraf_np_prod`,
  with privileges assigned to this database. No migration or data copy ran.
  Read-only PDO login with these credentials passed; the schema contains zero
  tables. Target server version: **11.4.13-MariaDB-cll-lve**.
- Generated a fresh application key and database/mail credentials on the server.
  `/home/codecraf/nordipass-production/shared/.env` has mode **0600**. Secret
  contents were not returned or copied to local source, evidence or archives.
- Created mailbox `hello@nordipass.com` with a 250 MB quota. cPanel reports SMTP
  `marisha.oderland.com:465`; certificate validation and authentication passed.
  No mail was sent; actual delivery and invitation acceptance remain unverified.
- Queues, sessions and coordination cache use MySQL. Fortnox, billing and
  commercial checkout remain disabled. No production cron was installed.
- Staging `current` remained
  `staging-20261011-fe85adb955de` throughout the provisioning checks.

The initial domain-creation invocation used cpapi2's documented `--user` option,
which the host rejects for a non-root account. Retrying as the current account,
without that root-only switch, succeeded. No privilege elevation was attempted.

## Domain blocker

The registrar screen supplied by the user reports Active and registration date
17 October 2026. Public DNS checks nevertheless returned:

| Check | Observed value |
|---|---|
| Public NS | suspension1.mydomainprovider.com, suspension2.mydomainprovider.com |
| Public A via 1.1.1.1 | 52.213.114.86 |
| A queried directly from dns1.oderland.com | 46.16.236.20 |
| Origin HTTPS with certificate verification | Hostname mismatch; not accepted |
| Authoritative mail zone | Oderland MX records, SPF and default DKIM record present |
| Public SPF/DKIM validation | Invalid against the current suspension DNS |

Do not infer the suspension cause from the NS names alone. The user subsequently
reported changing the nameservers to Oderland. At **07:26:27 UTC**, a direct query
to `a.gtld-servers.net` still returned both suspension nameservers. Origin TLS
validation still failed for both apex and www with hostname mismatch.
No authenticated registrar browser is available in this chat.
The agent did not change registrar delegation. Creating the cPanel domain did
create its server-side DNS zone; that is separate from public delegation.

Use `dns1.oderland.com`, `dns2.oderland.com`, `dns3.oderland.com`, as documented
by [Oderland](https://www.oderland.se/support/en/artikel/what-are-oderlands-name-servers-dns-servers/).
After propagation, verify apex/www, mail authentication, AutoSSL certificates and
canonical HTTPS redirect. Public exposure remains closed during preparation.

At **07:23:25 UTC**, the provider's built-in ACME path passed a direct origin
check for both names. A temporary extensionless static token returned **200**;
`/`, `/.env` and `/.well-known/acme-challenge/.htaccess` returned **403**.
The probe was removed and the original bootstrap denial is unchanged. No custom
ACME access rules remain. This verifies the origin challenge path, not public DCV
or certificate issuance while delegation still points elsewhere.

## Source and verification

The checkout already contained substantial uncommitted application, UI,
translation, Fortnox and import changes. They were preserved. This preparation
adds the production profile/runbook and two minimal dependency security corrections.

The production audit found
[CVE-2026-108082](https://symfony.com/cve-2026-108082) in
`symfony/http-foundation` **8.1.1**. It was updated alone to **8.1.9** with
`--minimal-changes`; composer.json is unchanged. The first full application test
attempt was interrupted before dependency installation, and a fresh run was
started after this correction. Do not count the interrupted run as acceptance.

Completed checks:

- Infrastructure on the final dependencies: **169 passed / 381 assertions**.
- PHPStan after the update: **zero errors**.
- Pint check: passed; no application PHP implementation changed in this work.
- Node **22.23.3** frontend build: passed. Production npm audit: **zero advisories**.
- Frontend JavaScript regressions: **16 passed**, no skips.
- Production Composer audit after the correction: **zero advisories**.
- Composer strict validation after the lock change: passed.
- Artifact tests on Windows: **19 passed, 4 Unix-only skips**.
- All native Linux release-tooling fixtures: **65 passed, no skips**. The first
  attempt had 11 lifecycle failures because `/tmp` disallowed executable fixture
  hooks. A private per-run TMPDIR under the prepared account fixed the fixture
  environment; no deploy safety checks or production system policy were relaxed.
- Isolated production dependencies installed using `--no-dev` and the lock file;
  Laravel package discovery passed without server credentials or application data.
- Production profile loaded through Laravel's configuration bootstrap: **24
  checks passed**, covering domain, drivers, cookie security, SMTP, readiness and
  disabled external integrations. Development test/static-analysis packages are
  absent from the isolated production dependency tree. Local platform checks pass.

The full Laravel suite was stopped after approximately 35 minutes without a
final result. It repeatedly recreated the testing schema; diagnostic snapshots
showed active DDL rather than a blocked query. **The full suite is not accepted.**
Run it against the fixed final release before production publication. Full dependency
audit also identified the pre-existing dev-only `symfony/yaml` advisory
[CVE-2026-109413](https://symfony.com/cve-2026-109413). After stopping the full
suite, YAML alone was updated from **8.1.1** to **8.1.9**. The final
`composer audit --locked`, including dev dependencies, reports **zero advisories**.
Both affected OpenAPI test files passed: **18 tests / 79 assertions**. Final
PHPStan again reports zero errors; strict Composer validation passes. Production
dependency versions are unchanged by the YAML correction; the isolated source
lock was refreshed and all **89** installed production package versions match it.

Local logs and the isolated source snapshot are in
`storage/framework/production-preparation-20261017/` and
`storage/framework/production-preparation-*.log`. The snapshot is explicitly an
uncommitted working-tree preparation based on
`fe85adb955deee7cf18786063a9ecf302df0b99e`, with per-file SHA-256 evidence. It is
not an accepted executable release tied to that commit. No production artifact
was labeled as the exact contents of an older Git revision.
The final application-source snapshot contains **1,149 files**, SHA-256
`6f41a11bf46a57fa90b17bfbaffe55365e7359f98a099348adc8fd23fe8489eb`.
That digest excludes vendor and runtime caches; its file manifest states this scope.

## Inactive candidate prepared on Oderland

The exact source snapshot and its production dependencies were packaged as
`candidate-20261017-6f41a11bf46a.tar.gz` (**10,361,873 bytes**). Archive SHA-256:

```text
0f822f601224579275f0cd9f6f9efca3ee1d685db9ab033b2cd174b27da20f15
```

Every application-source file was compared with the current checkout before
packaging. The archive excludes environment files, application data, runtime
caches, development dependencies and workspace attachments. Its metadata uses
`inactive_uncommitted_candidate` and explicitly records unaccepted production
and full-suite status; the old base commit is not presented as its exact revision.

The archive and checksum were uploaded to the isolated production artifacts
directory. At **07:27:41 UTC**, the server verified the archive digest and all
**9,216 packaged file digests**, extracted it under
`/home/codecraf/nordipass-production/releases/candidate-20261017-6f41a11bf46a`,
and passed these checks with target PHP **8.4.26**:

- Laravel package discovery.
- Production configuration cache.
- Route cache.
- Blade view compilation.

The candidate links the existing private server environment; its generated
caches and temporary storage remain inside the private candidate directory.
No migration, seeding, scheduler, queue worker, activation or external-provider
request was invoked by this preparation. Production `current` still resolves to
`releases/bootstrap`, and staging still resolves to
`staging-20261011-fe85adb955de`. The server retains a sanitized summary at
`shared/candidate-preparation.json` and private command logs beside it.

A concrete proposed release commit list contains **162 changed/new files**
under application, configuration, migration, translation, view, route, deployment,
documentation and test paths, plus composer.lock. Storage logs, attachments,
temporary files, Python caches, demo deletions and test-output artifacts are
excluded. Git staging resolved 11 apparent modifications to unchanged content
after line-ending normalization, leaving **151 files with actual changes** in
the authorized scope. The user explicitly authorized this local release commit
under AGENTS.md. A push is not authorized.

## Remaining before opening the pilot

1. Resolve public delegation/contact status, TLS and actual invitation delivery.
2. Complete the full quality gate and build the executable artifact from the
   authorized local release commit. A push still requires explicit permission.
3. Implement and rehearse production-specific first-install and lifecycle hooks,
   including a policy that keeps external integrations disabled. The existing
   staging hooks intentionally reject production and were not installed here.
4. Verify production scheduler/finite database workers, monitor freshness and
   exercise realistic supplier import, approval, publication, assets and QR links.
5. Rehearse populated database/files restoration and off-host executable/data
   recovery against an explicitly designated isolated fixture.
6. Install the verified release, migrate under the qualified writer boundary,
   accept health/security/mobile behavior, and then admit the first pilot users.

The [production runbook](../../deploy/site/oderland-production/README.md) contains
the target configuration and deployment gates. Local or Linux fixture checks do
not substitute for production lifecycle and customer-flow acceptance.
