# R3.7 — Fortnox Integration Foundation

**Status:** ACCEPTED — code gates closed; live Fortnox sandbox credentials pending
**Date:** 2026-08-23

## Scope of this stage

R3.7 closes the Fortnox integration foundation: OAuth connection, encrypted token
storage, tenant-scoped article sync, source-of-truth conflict handling, API/web
surfaces, and hourly scheduled sync orchestration. Real Fortnox sandbox
credentials are intentionally outside the repository and must be supplied by an
authorized Fortnox developer account before live E2E can run.

### In scope

1. OAuth connect/callback with cryptographically secure, single-use, expiring `state` verification.
2. Encrypted-at-rest Fortnox token storage on tenant-scoped connections.
3. Tenant-scoped `fortnox_connections` with a strict status lifecycle.
4. Mapping Fortnox article number ↔ NordiPass product/variant (external identity mapping).
5. Source-of-truth matrix implementation per ADR-R3-006.
6. Conflict detection and reconciliation records with explicit resolution.
7. Bounded provider retries, sync history, and per-sync idempotency.
8. Audit events for connect, disconnect, sync, and conflict resolution.
9. A contract-based provider layer: in-process fake provider (default for local/testing)
   and a hardened HTTP provider behind config; the whole feature behind
   `FEATURE_FORTNOX_INTEGRATION`.
10. Scheduled sync command `fortnox:sync` with hourly Laravel scheduler
    registration, tenant context setup, active-connection filtering, per-company
    targeting, result summaries, and safe skips for missing connection actors.

### Out of scope (later slices)

- Fortnox webhooks and polling fallback.
- Real Fortnox sandbox credentials and live E2E.
- Stock catalog columns. Fortnox price now maps into canonical product
  commerce data; stock is still recorded at the sync boundary only.

### Credentials and demo flow

No Fortnox sandbox credentials exist in this project (R3.1 decision). Local and
testing use the fake provider and require none. When `FORTNOX_CLIENT_ID` is
empty, the fake provider redirects the browser straight back to the local
callback with a simulated code, so Connect → sync → conflicts works end-to-end
in a local browser without contacting Fortnox. The real provider activates when
`FORTNOX_PROVIDER=http` and real credentials are supplied.

For live sandbox acceptance, an authorized Fortnox developer must create or use
a Fortnox developer portal integration, create a test database/sandbox, register
the callback URL `APP_URL/settings/integrations/fortnox/callback`, grant the
`article` scope, and provide `FORTNOX_CLIENT_ID`/`FORTNOX_CLIENT_SECRET`.

## Source-of-truth matrix (implemented)

| Field | Authority | Sync behavior |
|-------|-----------|---------------|
| Article number | Fortnox | SKU on creation only; never overwrites |
| Name | Bidirectional | Fortnox → NordiPass unless NordiPass override or conflict |
| Description | Bidirectional | Fortnox → NordiPass unless NordiPass override or conflict |
| Price | Fortnox | SalesPrice maps into product commerce and is visible on product UI |
| Stock | Fortnox | Recorded at sync boundary; no catalog column yet |
| GTIN/EAN | Bidirectional | Fortnox → NordiPass unless NordiPass override or conflict |
| Brand / Manufacturer | NordiPass | Never touched by sync |
| DPP, documents, readiness, publication | NordiPass | Never touched by sync |

A conflict is raised when the catalog value and the incoming Fortnox value both
differ from the last applied value for a field. Resolution is explicit:
`fortnox` applies the provider value, `nordipass` keeps the catalog value and
records a per-field override that future syncs respect.

## Provider contract

`App\Services\Fortnox\Contracts\FortnoxProvider` defines the complete external
surface: authorization URL, code exchange, token refresh, and article listing.
`FakeFortnoxProvider` implements the contract in-process (default for
local/testing). `HttpFortnoxProvider` implements it against the real API with
allowlisted Fortnox API/OAuth hosts, HTTPS enforcement, private-range rejection
(SSRF control), timeouts, and typed error mapping.

## Stage gates

| Gate | Evidence |
|------|----------|
| OAuth state validation | Single-use, expiring, tenant-bound state; hash-only storage |
| Token encryption | `encrypted:json` payload; plaintext never persisted or logged |
| Source-of-truth matrix | Unit + feature tests per field |
| Conflict reconciliation | Open/resolved lifecycle with override records |
| Tenant-scoped mappings | Composite FKs, 404 cross-tenant behavior, negative tests |
| Bounded retries | Attempt cap, retryable/terminal error mapping, attempt counters |
| Scheduled sync | `fortnox:sync` command, hourly scheduler entry, tenant context setup |

## Validation commands

```bash
php artisan migrate:fresh --env=testing
php artisan test tests\Unit\Fortnox tests\Feature\Fortnox tests\Feature\Api\V1\Catalog\OpenApiSpecificationTest.php
php artisan schedule:list --env=testing
php artisan route:list --path=fortnox --env=testing
vendor/bin/pint --test
vendor/bin/phpstan analyse
npm run build
composer audit
npm audit
```
