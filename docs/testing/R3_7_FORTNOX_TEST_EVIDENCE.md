# R3.7 Fortnox Foundation — Test Evidence

**Date:** 2026-08-23
**Status:** ACCEPTED — code gates closed; live Fortnox sandbox credentials pending

## Scope verified

The R3.7 foundation slice covers OAuth connect with state validation, encrypted
token storage, tenant-scoped connections, article identity mappings, the
ADR-R3-006 source-of-truth matrix, conflict reconciliation, bounded retries,
sync history, audit events, hourly scheduled sync orchestration, and a
contract-based provider layer (in-process fake for local/testing, hardened HTTP
provider behind `FEATURE_FORTNOX_INTEGRATION`).

## Verification matrix

| Gate | Evidence | Result |
|------|----------|--------|
| OAuth state validation | Single-use, expiring, tenant-bound SHA-256 state rows; replay, expiry, and cross-tenant negatives | Pass |
| Token encryption at rest | `token_payload` uses the `encrypted:json` cast; raw DB value contains no plaintext token | Pass |
| Tenant-scoped connections | One connection row per company, composite FKs, cross-tenant 404 behavior | Pass |
| External identity mapping | Article number → SKU matching or new product creation; one mapping per article number per connection | Pass |
| Source-of-truth matrix | Unit contract per field; sync updates name/description/price/GTIN; SKU never overwritten; stock boundary-only | Pass |
| Conflict reconciliation | Both-sides-changed raises one open conflict; `fortnox`/`nordipass` resolutions with override records | Pass |
| Bounded retries | Retryable failures retried up to the configured cap with attempt counters; terminal failures fail the run | Pass |
| Sync history and idempotency | Runs with counters, idempotency keys, and article-level dedupe via `lastModified` | Pass |
| Audit events | `fortnox.connection.connected/disconnected`, `fortnox.sync.started/completed/failed`, `fortnox.conflict.resolved` | Pass |
| Provider contract | Fake provider default in local/testing; HTTP provider with HTTPS-only, Fortnox API/OAuth host allowlist, private-range rejection, typed error mapping | Pass |
| Scheduled sync | `fortnox:sync` command, hourly scheduler registration, tenant context setup through token company context, company targeting, disabled-feature no-op, missing-actor skip | Pass |
| API surface | 7 endpoints behind dedicated `fortnox.read`/`fortnox.write` abilities; OpenAPI 110 operations | Pass |
| Feature flag | `feature.fortnox` middleware hides web and API routes when disabled | Pass |

## Test evidence

New R3.7 tests: `tests/Unit/Fortnox` (2 files) and `tests/Feature/Fortnox`
(4 files, 60 tests total in the Fortnox suites).

- `FortnoxConnectionFlowTest` — web OAuth connect/callback, encrypted storage,
  state replay/expiry/cross-tenant negatives, permission matrix, disconnect and
  reconnect, feature-flag hiding.
- `FortnoxSyncTest` — product/variant/SKU creation, SKU-based mapping, article
  idempotency, field updates, conflict raise/resolve (both directions),
  bounded retry behavior, terminal failure, token refresh, reconnect-required,
  run idempotency.
- `FortnoxApiTest` — abilities, cross-tenant concealment, feature flag, sync
  lifecycle, conflict listing and resolution.
- `FortnoxScheduledSyncTest` — scheduled command over active connections,
  single-company targeting, feature-flag no-op, missing connected-user skip,
  and hourly scheduler registration.
- `HttpFortnoxProviderTest` — SSRF guard (scheme, allowlist, private/reserved
  ranges), default allowlist coverage for `api.fortnox.se` and
  `apps.fortnox.se`, token exchange, typed error mapping, connection failures,
  payload parsing and pagination.
- `FortnoxSourceOfTruthMatrixTest` — ADR-R3-006 authority matrix contract.

## Full regression

```text
php artisan test       → 2245 tests, 2244 passed, 1 pre-existing skipped, 9097 assertions
vendor/bin/pint --test → passed
vendor/bin/phpstan     → 0 errors
npm run build          → passed
composer audit         → no advisories
npm audit              → 0 vulnerabilities
```

Additional verification on 2026-08-23:

```text
php artisan migrate:fresh --env=testing --force
  → passed, including 2026_08_21_000001_add_fortnox_integration_foundation

php artisan test tests\Unit\Fortnox tests\Feature\Fortnox tests\Feature\Api\V1\Catalog\OpenApiSpecificationTest.php
  → 76 tests, 76 passed, 306 assertions

php artisan route:list --path=fortnox
  → 13 routes; API route names normalized as api.v1.fortnox.*

php artisan schedule:list --env=testing
  → hourly php artisan fortnox:sync --all-companies registered

php artisan list fortnox --env=testing
  → fortnox:sync command registered
```

Live OAuth smoke on 2026-08-27:

```text
Fortnox OAuth connect
  → passed; local connection stored as active with Article scope

php artisan fortnox:sync --all-companies --format=json --env=local
  → reached api.fortnox.se with the real access token
  → blocked by Fortnox tenant licensing:
    ErrorInformation code 2001101, "Det finns ingen aktiv licens för önskat scope."
```

This confirms the NordiPass OAuth callback, encrypted token persistence, and
real outbound provider path. Full live article import remains pending until the
Fortnox test company has an active license/module for the requested `Article`
permission.

Repeat live check after reconnect on 2026-08-27:

```text
Fortnox OAuth reconnect
  → passed; connection active, scopes=["article"], connected_at=2026-08-27T08:59:13Z

php artisan fortnox:sync --all-companies --format=json --env=local
  → still blocked by Fortnox ErrorInformation code 2001101
  → last_synced_at remains empty; no Fortnox mappings created
```

## Boundaries retained

- Fortnox webhooks are not implemented in this slice; manual and scheduled
  article sync are implemented.
- Real Fortnox credentials are not required; the fake provider covers the whole
  contract, and the HTTP provider is exercised through `Http::fake` plus its
  SSRF guard.
- Price maps into canonical product commerce data and is visible on product UI.
  Stock has no catalog target column yet and is recorded at the sync boundary only.
