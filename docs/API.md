# NordiPass API v1

Stage 8 provides the API foundation only. Product, Document, QR, DPP, import, integration, billing, and webhook endpoints do not exist yet.

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

Abilities do not imply one another. Wildcard (`*`) and future product abilities cannot be issued.

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

Stable codes are `unauthenticated`, `forbidden`, `validation_error`, `current_company_missing`, `company_inactive`, `token_expired`, `token_invalid`, `token_ability_missing`, `resource_not_found`, `rate_limited`, and `internal_error`. Invalid/revoked/expired tokens return 401; missing abilities and archived companies return 403; suspended companies return 423; validation returns 422; throttling returns 429.

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

The canonical contract is [`openapi.yaml`](openapi.yaml).
