# R3 — Test Strategy

## Status: R3.1 Architecture Lock — APPROVED

R3 testing keeps the R2 baseline closed and treats the accepted MySQL 8 implementation as the source of truth. SQLite, driver-based skips, and weakened MySQL constraints are not valid R3 test strategies.

## Test Levels

| Test level | Coverage | Mandatory stages |
|------------|----------|------------------|
| Unit/domain | State transitions, scoring algorithms, entitlement decisions, import mapping, conflict policies, approval rules | R3.2, R3.5, R3.6, R3.7, R3.8, R3.10 |
| MySQL integration | Foreign keys, unique constraints, JSON checks, triggers, transactions, locks, migrations, backfills, R2 upgrade | Every migration stage |
| Feature/web | Authorization, tenant isolation, validation, CSRF, publication, document workflow, onboarding | R3.2-R3.18 |
| API | `/api/v1` compatibility, token abilities, Problem Details, pagination, idempotency, rate limits | R3.6, R3.7, R3.8, R3.13 |
| Jobs/events | Idempotent retries, duplicate/out-of-order delivery, deleted entities, stale revisions, provider failures | R3.6, R3.7, R3.8, R3.9, R3.14 |
| Browser/E2E | Onboarding, catalog, Passport editing, readiness, publication, QR, public Passport, import, Fortnox, billing | R3.11, R3.12, R3.18, R3.19 |
| Security | Cross-tenant access, SSRF, OAuth state, webhook signature/replay, upload abuse, permission bypass, secret leakage | R3.7, R3.8, R3.13, R3.15, R3.16 |
| Performance | Large catalogs, large imports, public resolver latency, analytics ingestion, queue load, API pagination | R3.6, R3.9, R3.11, R3.13, R3.17 |
| Accessibility | Keyboard, focus, screen-reader semantics, error summaries, modals, mobile public pages | R3.2, R3.11, R3.18 |

## MySQL 8 Policy

All automated database tests must run with:

```text
DB_CONNECTION=mysql
DB_DATABASE=<MySQL database>
```

Forbidden in R3:

```text
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
SQLite compatibility branches
driver-based test skipping
weakening MySQL constraints for tests
```

## Stage Test Contracts

| Stage | Minimum test additions |
|-------|------------------------|
| R3.2 | Profile version CRUD/domain tests, immutable profile version tests, algorithm-version reproducibility, v1 migration/fallback, accessibility checks for readiness UX |
| R3.3 | DPP v2 payload validator/normalizer tests, product/variant override tests, published snapshot serialization tests |
| R3.4 | Document type/certificate metadata validation, review/approval policy tests, expiration state tests, public/private visibility tests |
| R3.5 | Category template assignment tests, required attribute diagnostics, attribute option deprecation lifecycle, safe migration dry-run tests |
| R3.6 | CSV/XLSX preview, chunked import, idempotency/resume, error report, export parity, large-file tests |
| R3.7 | Fortnox OAuth state, encrypted token persistence, mapping idempotency, source-of-truth conflict matrix, retry/reconciliation jobs |
| R3.8 | Stripe webhook signature/idempotency, subscription projection, entitlement enforcement, usage limits, reconciliation drift |
| R3.9 | QR scan/passport view ingestion, deduplication, bot filtering, aggregation, retention/deletion and privacy-safe exports |
| R3.10 | Granular role policy matrix, maker-checker negative tests, approval state transitions, approval audit |
| R3.11 | Public Passport v2 mobile/accessibility tests, immutable published-version reads, withdrawn/archived state rendering |
| R3.12 | QR decode verification, contrast/quiet-zone tests, SVG/PNG/PDF output tests, batch ZIP generation |
| R3.13 | OpenAPI schema parity, token ability matrix, pagination/rate-limit/idempotency, webhook signature/replay/retry/dead-letter tests |
| R3.14 | Notification preferences, delivery fanout, job status, safe retry/cancel, deleted-entity and stale-event tests |
| R3.15 | Restricted support-access tests, feature flag audit tests, tenant health/job recovery tests |
| R3.16 | GDPR export/deletion/retention, CSP, upload hardening, SSRF, dependency scanning, cross-tenant full sweep |
| R3.17 | Query-count baselines, resolver latency, queue lag, import throughput, analytics ingestion load |
| R3.18 | Guided onboarding E2E, sample data isolation, first-publication journey, billing activation, support path |
| R3.19 | Fresh install, R2 upgrade, full regression, all E2E journeys, backup/restore, performance/accessibility, final verdict |

## Regression Policy

R3.1 does not repeat R2 final acceptance as a product decision. It does run the current validation suite after documentation changes to confirm no R2 regression was introduced by the R3.1 package. If the local environment blocks a command, the final report must state the exact command, exit code, and environmental cause.

## Acceptance Evidence Format

Every R3 stage report must record:

```text
Command
Exit code
Tests/assertions or checks
Failures
Skipped
Duration
Environment notes
```

No stage may claim acceptance on screenshots or manual observation alone when an automated project command exists.
