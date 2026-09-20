# NordiPass API v1

The versioned API covers company-scoped catalog, document, passport, production import, product export, Fortnox integration, and outbound webhook workflows. The OpenAPI contract remains additive within `/api/v1`.

## Base URL and authentication

The API uses path versioning at `https://example.com/api/v1`. `GET /health` is public. `GET /me`, `GET /company`, and `GET /company/members` require a company-scoped Laravel Sanctum bearer token.

Create tokens in the authenticated web UI at `/settings/api-tokens`. Only an owner or admin of the current active company may list, create, or revoke tokens. The raw secret appears once in the direct creation response, which has `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. It cannot be recovered later.

```bash
curl \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  https://example.com/api/v1/me
```

## Tenant isolation

Every normal token belongs to one User + Company pair. Its stored `company_id`, not a web session or request parameter, selects the tenant. `company_id`, `company_uuid`, and `tenant_id` in a query or body never change token scope. Cookie/session authentication without a bearer token is rejected for scoped endpoints.

A token stops working when it expires or is revoked, its user is suspended, its company is suspended/archived/deleted, or its creator loses membership. Membership removal deletes only that user's tokens for the removed company in the same transaction. Tokens for other companies remain. A role downgrade does not revoke existing tokens while membership exists; abilities remain independent of permission to create tokens.

## Abilities

| Ability | Endpoints |
| --- | --- |
| `company.read` | `GET /me`, `GET /company` |
| `members.read` | `GET /company/members` |
| `imports.read` | List import jobs, preview rows, errors, and mapping templates |
| `imports.write` | Upload CSV/XLSX files, save mappings, preview, apply, and resume chunks |
| `exports.read` | Download tenant-scoped product CSV/XLSX exports |
| `fortnox.read` | `GET /fortnox/connection`, sync runs, mappings, and conflicts |
| `fortnox.write` | `POST /fortnox/syncs`, `POST /fortnox/conflicts/{conflict}/resolve` |
| `webhooks.manage` | Create/update/disable webhook endpoints and read webhook delivery logs |

Abilities do not imply one another. Production imports require a persisted preview before write. Repeating an apply request resumes from the saved row cursor and never reapplies completed records; upload idempotency keys are tenant- and file-content-bound.

## Fortnox integration

Fortnox endpoints are hidden until `FEATURE_FORTNOX_INTEGRATION=true`. OAuth connect/callback is a web flow at `/settings/integrations/fortnox`; tokens are stored encrypted at rest and never appear in API responses. A manual sync (`fortnox.write`) fetches articles changed after the last sync cursor, applies the ADR-R3-006 source-of-truth matrix, and records conflicts that stay open until explicitly resolved with `fortnox` (apply provider value) or `nordipass` (keep catalog value and record a per-field override). Provider failures return `fortnox_provider_error` with HTTP 502 and a `retryable` flag.

## Webhooks

Webhook endpoint management uses `webhooks.manage`.

- `GET /webhooks/endpoints` lists tenant-scoped endpoints.
- `POST /webhooks/endpoints` creates an HTTPS endpoint with a caller supplied secret and subscribed events.
- `PATCH /webhooks/endpoints/{endpoint}` rotates URL, secret, events, or status.
- `POST /webhooks/endpoints/{endpoint}/disable` disables delivery without deleting history.
- `GET /webhooks/deliveries` lists tenant-scoped delivery attempts with optional `status`, `event_type`, and `per_page` filters.

Endpoint secrets are encrypted at rest and never returned by API responses. Delivery requests include `NordiPass-Signature: t=<unix>,v1=<hmac>`, where the HMAC signs `<timestamp>.<raw-json-body>` with SHA-256 and the endpoint secret. Consumers should reject timestamps outside `WEBHOOK_SIGNATURE_TOLERANCE_SECONDS` and use `NordiPass-Event-ID` plus `Idempotency-Key` for replay/idempotency handling.

## Expiration, revocation, and pruning

Presets are 30 days, 90 days, and one year. The default is 90 days and maximum is 365. Non-expiring tokens require `API_ALLOW_NON_EXPIRING_TOKENS=true` and are disabled by default.

Revocation deletes the Sanctum token hash. Audit history preserves `api_token.created` and `api_token.revoked` without raw secrets or hashes. The daily `nordipass:prune-api-tokens` command removes old expired/orphaned tokens and, when configured, old inactive-user tokens. It supports `--dry-run` and `--days=`.

Sanctum updates `last_used_at` after successful bearer authentication. This provides useful operational visibility but creates one database write per accepted API request; higher-volume deployments should account for that write load before expanding the business API.

## Responses and errors

```json
{
  "data": {},
  "meta": { "request_id": "8d8a2a98-68c0-4cf2-a54f-a8f03b074b72" },
  "error": null
}
```

```json
{
  "data": null,
  "meta": { "request_id": "8d8a2a98-68c0-4cf2-a54f-a8f03b074b72" },
  "error": {
    "code": "token_ability_missing",
    "message": "The API token does not have the required ability.",
    "details": {}
  }
}
```

Responses include `X-Request-ID`, `X-Content-Type-Options: nosniff`, and `Cache-Control: no-store`. Dates are ISO 8601 and resources use UUIDs rather than numeric model IDs.

Stable codes are `unauthenticated`, `forbidden`, `validation_error`, `current_company_missing`, `company_inactive`, `token_expired`, `token_invalid`, `token_ability_missing`, `resource_not_found`, `rate_limited`, `fortnox_provider_error`, and `internal_error`. Invalid/revoked/expired tokens return 401; missing abilities and archived companies return 403; suspended companies return 423; validation returns 422; throttling returns 429; Fortnox provider failures return 502.

## Pagination

`GET /company/members` defaults to 25 records. `per_page` accepts 1–100. Metadata includes `current_page`, `per_page`, `total`, and `last_page`.

```bash
curl \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  "https://example.com/api/v1/company/members?per_page=50&page=2"
```

## Rate limits and CORS

- Health: 60 requests/minute per IP.
- Authenticated API: 120 requests/minute per token ID.
- Token creation: 10 requests/minute per user + company.
- Token revocation: 30 requests/minute per user + company.

Raw bearer tokens are never limiter keys. Allowed browser origins come from `API_ALLOWED_ORIGINS`. Credentialed CORS is disabled; production must set explicit origins.

The canonical contract is [`api/openapi-v1.yaml`](api/openapi-v1.yaml).
