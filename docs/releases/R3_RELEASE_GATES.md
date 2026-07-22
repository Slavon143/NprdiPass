# R3 — Release Gates

## Status: R3.1 Architecture Lock — APPROVED

R3 gates are release-blocking. A stage may be accepted only when its gate evidence is produced against MySQL 8 and no unresolved tenant-isolation, data-loss, backward-compatibility, or public-URL regression remains.

## Gate Matrix

| Gate | Required evidence | Blocking threshold | Owner stage |
|------|-------------------|--------------------|-------------|
| Architecture Gate | Scope lock, ADR-R3-001 through ADR-R3-012, dependency map, migration strategy, feature flag plan | Any open cross-stage decision blocks R3.2 | R3.1 |
| Data Gate | Fresh MySQL install, R2-to-R3 upgrade path, migration status, orphan checks, immutable published snapshot checks | Any destructive migration without expand/backfill/switch/contract plan blocks release | Every migration stage; final in R3.19 |
| Security Gate | Authorization tests, cross-tenant negative tests, CSRF/webhook/OAuth/rate-limit/upload checks where applicable | Any critical/high issue; any cross-tenant read/write | R3.16, with per-stage pre-gates |
| Commercial Gate | Stripe checkout/subscription/customer portal/webhook/reconciliation/entitlement enforcement evidence | Any entitlement bypass or billing drift without reconciliation | R3.8, final in R3.19 |
| Integration Gate | Fortnox OAuth/import/sync/conflict/retry/audit evidence; provider failure simulations | Any token leak, wrong source-of-truth overwrite, or unbounded retry loop | R3.7, final in R3.19 |
| Public Journey Gate | Onboarding → product/import → Passport → readiness → publish → QR → public page | Any broken stable QR/public URL, unreadable public Passport, or missing accessibility baseline | R3.18, final in R3.19 |
| Operations Gate | Metrics, alert thresholds, failed-job recovery, backup/restore verification, runbooks | Any unobservable critical async path or untested restore | R3.15/R3.17, final in R3.19 |
| Final Gate | Full R3.19 acceptance report with test/build/static-analysis/security evidence | Any mandatory R3 scope missing or failing | R3.19 |

## Stage Gate Requirements

| Stage | Release-blocking checks |
|-------|-------------------------|
| R3.2 Readiness Profiles v2 | Historical reproducibility, immutable profile versions, profile fallback from `nordipass-pilot` v1, publication re-evaluation under lock |
| R3.3 Advanced DPP Sections | Section validation, product/variant inheritance, published serialization freezes mutable draft data |
| R3.4 Documents and Compliance | Version immutability, review/approval authorization, expiry notifications contract, public/private visibility |
| R3.5 Taxonomy Governance | Required attribute diagnostics, safe option lifecycle, no universal migration engine, profile/category assignment integrity |
| R3.6 Import/Export | Preview before write, idempotency, resume, chunk boundaries, large-file MySQL tests, tenant-scoped temp storage |
| R3.7 Fortnox | OAuth state, token encryption, source-of-truth matrix, conflict reconciliation, tenant-scoped mappings |
| R3.8 Billing | Stripe signature verification, webhook inbox idempotency, server-side entitlement checks, reconciliation drift alerts |
| R3.9 Analytics | No raw IP/full user-agent storage by default, aggregation/retention, bot/internal traffic handling, scan/view ingestion limits |
| R3.10 Roles and Approval | Maker-checker separation, approval audit, no billing entitlements encoded as permissions |
| R3.11 Public Passport v2 | Mobile/accessibility/performance/SEO checks, immutable published-version reads only |
| R3.12 QR and Labels | Stable resolver, SVG/PNG/PDF generation, decode verification, quiet-zone/contrast validation |
| R3.13 API and Webhooks | `/api/v1` additive compatibility, OpenAPI update, Problem Details, idempotency/rate-limit/signature/replay tests |
| R3.14 Notifications and Job Center | Tenant-aware idempotent jobs, safe retry/cancel, deleted-entity handling, user preferences |
| R3.15 Platform Operations | Restricted support access, feature flag audit, job monitoring, tenant health and incident runbooks |
| R3.16 Security and Privacy | GDPR workflows, retention/deletion/export tests, upload hardening, dependency scanning |
| R3.17 Performance and Observability | SLO dashboard, query profiling, queue lag, resolver latency, large-catalog/load baselines |
| R3.18 Public Beta Onboarding | Commercial journey completion and recovery paths, sample data isolation, help/support path |
| R3.19 Final Acceptance | Fresh install, R2 upgrade, full regression, E2E journeys, backup/restore, performance, accessibility, final verdict |

## Required Validation Commands

The R3.19 final gate must execute the project validation suite defined by R3.1:

```bash
composer validate
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan migrate:fresh --env=testing
php artisan migrate:status --env=testing
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
npm ci
npm run build
composer audit
npm audit
php artisan route:list
php artisan config:cache
php artisan route:cache
php artisan view:cache
git diff --check
git status --short
```

Environment/tooling failures are recorded as gate failures unless a later clean run in the same source state supersedes them.
