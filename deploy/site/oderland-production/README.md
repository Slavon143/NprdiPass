# NordiPass first production pilot — nordipass.com

Status: preparation only; the application is not live. On 17 October 2026 the
separate cPanel domain, closed bootstrap document root, empty production database,
database user and SMTP mailbox were provisioned. See the
[preparation evidence](../../../docs/releases/2026_10_17_FIRST_PRODUCTION_PREPARATION.md).
No application migrations, production cron or accepted release are installed.
An inactive candidate is now uploaded and bootstrapped in
`releases/candidate-20261017-6f41a11bf46a`. The domain still serves the closed
bootstrap directory; this candidate has not been activated.

## Target and isolation

Use the existing Oderland account, with a separate application root, MySQL
database/user, APP_KEY, storage, cookie name, backups, locks and cron wrappers.
Verified root: `/home/codecraf/nordipass-production`; cPanel serves its `public`
symlink. Keep `/home/codecraf/nordipass-staging` and its domain intact.
Start with an empty production database and new pilot accounts. Copying staging
users, demo data, integration tokens, sessions or published snapshots is not part
of this first release. An intentional data migration needs its own reviewed plan.

Canonical origin: `https://nordipass.com`. Use it for APP_URL and
PASSPORT_PUBLIC_BASE_URL before creating production passports/QR codes.
Configure `www.nordipass.com` as an HTTPS redirect to the canonical origin at the
web-server layer; it is not a second application origin.

## Domain and site setup

1. Inspect the account's actual server IP, supported PHP version/extensions,
   current domain mappings, DNS provider and proxy chain. Do not infer an IP
   from the staging hostname or change nameservers blindly: existing mail/DNS
   records must be preserved.
2. Add nordipass.com in cPanel with an independent document root resolving to
   the production release's `public/` directory. Never serve the application
   root, shared storage, `.env`, vendor or release archives. Verify the supported
   symlink/document-root mapping before choosing the final layout.
3. Point the apex A record to the verified host address. Add AAAA only if the
   target IPv6 route is verified. Configure www to the same site for redirect.
   Issue and verify TLS for both names. Keep the application closed during setup.
4. Verify DNS, certificate chain, HTTPS redirect and Host-header rejection.
   Test using a local DNS override before cutover where possible.

Oderland's authoritative zone already answers A `46.16.236.20` and its mail MX,
SPF and DKIM records. At **07:26 UTC on 17 October**, a direct query to the `.com`
registry parent still delegates to
`suspension1.mydomainprovider.com` and `suspension2.mydomainprovider.com` and
public resolvers return A `52.213.114.86`. The user reports changing nameservers
to Oderland; that change is not yet visible at the registry parent. This is not
merely the workstation's resolver cache. The expected nameservers are
`dns1.oderland.com`, `dns2.oderland.com`, `dns3.oderland.com`. These are the
[official Oderland nameservers](https://www.oderland.se/support/en/artikel/what-are-oderlands-name-servers-dns-servers/).
No authenticated registrar browser session is available to the agent. Preserve
any intentional existing records; do not change account-wide DNS settings.

Both origin HTTPS certificates still fail hostname validation. The provider's
built-in HTTP ACME route was verified for apex and www: a temporary static token
returned 200, while `/`, `/.env` and the ACME `.htaccess` path returned 403. The
probe was removed; no custom ACME access rules were installed. Once delegation
reaches Oderland, run the certificate check and verify both names before opening
the application. Do not treat an insecure TLS request as certificate acceptance.

## Environment and background processing

Use [.env.example](.env.example) as the production profile. Database and SMTP
identities are the verified nonsecret production values. Empty APP_KEY, database
and SMTP passwords and proxy settings are intentional operator inputs. Fill them
privately on the server; never put secret values in release evidence. Generate
APP_KEY once for this environment. Use the real SMTP scheme/port supported by
the selected mailbox (the template suggests implicit TLS on 465). Confirm sender
authorization and SPF/DKIM/DMARC, then test invitation delivery and acceptance.
`INVITATION_MAILER=smtp` matters independently of MAIL_MAILER.

The prepared server environment now has its own APP_KEY and MySQL credentials
and uses `hello@nordipass.com` through the cPanel-provided endpoint
`marisha.oderland.com:465` with implicit TLS. TLS validation and SMTP authentication
passed without sending email. Passwords exist only in the private server
environment; use cPanel to manage the mailbox. Do not copy this server's private
environment back into the repository. Delivery, SPF/DKIM/DMARC and invitation
acceptance remain unaccepted until public DNS and actual delivery are verified.

Keep database queues, sessions and coordination cache on this Oderland plan.
Shared Redis is not accepted for critical state. Do not install Supervisor or
Horizon on Standard. Use bounded, non-overlapping cron workers and a separate
once-per-minute scheduler with production-only paths/locks. See
[the hosting process boundary](../../../docs/infrastructure/QUEUES_AND_SCHEDULER.md).
The worker arguments to qualify on target are:

```text
queue:work database --queue=webhooks,imports,mail,analytics,maintenance,default --sleep=1 --tries=5 --stop-when-empty --max-time=480 --timeout=300
```

QUEUE_RETRY_AFTER=360 must stay greater than the 300-second timeout. Inventory
every HTTP/CLI/cron writer before implementing stop/start controls. Required
scheduler and worker heartbeats intentionally keep readiness failing until both
process paths work. Test queue throughput, oldest-job age and heartbeat freshness.
Do not run the full scheduler as a harmless probe: it includes pruning and writes.

Fortnox and billing/commercial checkout remain disabled. Do not copy test or live
provider credentials. Catalogue imports are enabled for the pilot; acceptance
must include the intended supplier spreadsheet and explicit decimal format.
No live payment, license purchase or production Fortnox activation is included.

## Release and deployment gates

The uploaded candidate contains 9,216 verified files, including production-only
Composer dependencies and built frontend assets. Target PHP 8.4 passed package
discovery, configuration caching, route caching and Blade compilation. Its
`CANDIDATE.json` explicitly identifies an uncommitted working tree, records the
source digest, and states that production and the full test suite are unaccepted.
It is not a format-2 release artifact and must not be activated as one. Candidate
storage and generated caches are private to the candidate. The private server
environment is linked locally and has not entered the archive.

1. Fix the candidate source identity, including all intended uncommitted and
   untracked application changes. AGENTS.md requires explicit permission to
   commit/push. Do not label a working-tree archive as an accepted Git commit.
2. Pass focused regressions, the complete Laravel suite, PHPStan, Pint check,
   Node 22 build and dependency audits. Record exits, versions and source hashes.
   Build production dependencies in an isolated source tree with composer.lock;
   do not remove development dependencies from the working checkout.
3. Build/verify an executable format-2 archive with release_artifact.py; retain
   archive, checksums, manifest and an independently verified off-host copy.
4. Implement and rehearse production-specific pause-writers,
   verify-writers-stopped, verify-backup, verify-release and resume-writers hooks
   against the actual root/database/domain/cron inventory. **Staging hooks reject
   production by design. Do not edit their root constants or copy their success
   evidence as production acceptance.** No executable production hooks are
   supplied here before the target inventory is available.
5. Qualify first installation in isolation: deploy.sh currently expects an
   existing shared root and functioning writer/backup hooks. Do not bypass its
   checks to bootstrap a new site. Prepare and review the first-install procedure,
   then use the standard boundary for subsequent releases.
6. Verify native database/file backup and an actual isolated restore, with
   populated documents, catalogue media and published passport assets. The
   restore target must be separate from production and staging. Local backup
   presence alone is insufficient; verify off-host recovery too.
7. Apply reviewed migrations with production PHP 8.4 under the qualified writer
   boundary, run release:verify-schema and capacity checks, build caches, switch
   current and start fresh bounded processes. Never use migrate:fresh or copy the
   development .env. Initial role seeding must not create demo users.
8. Accept /up, /ready, nordipass:deploy-check, registration/email verification,
   login/member invitation, company isolation,
   supplier import preview/apply, document approval, passport publication,
   public asset/QR retrieval and mobile layout on the actual domain. Check that
   Fortnox and payment actions are unavailable. Rehearse failed activation and
   recovery before admitting pilot users.

Follow [the release checklist](../../../docs/infrastructure/RELEASE_CHECKLIST.md)
and [deployment contract](../../../docs/infrastructure/CI_AND_DEPLOYMENT.md).
DNS/TLS acceptance, local tests, staging and production are separate evidence.
