# ADR-R3-009 — API and Webhook Versioning

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None (extends R2 API foundation)

---

## Context

R2 established `/api/v1` with 12 token abilities. R3 extends the API with passports, documents, analytics, imports, and webhooks. Versioning policy must be defined to prevent breaking R2 API consumers.

## Decision

### API Versioning: URI Path-Based

```
/api/v1/...  — R2 compatible, additive changes only
/api/v2/...  — R4+ breaking changes (if ever needed)
```

### R3 API Compatibility Rules

| Change Type | Allowed in v1 | Requires v2 |
|-------------|:---:|:---:|
| Add new endpoint | Yes | — |
| Add optional field to response | Yes | — |
| Add optional parameter to request | Yes | — |
| Add new enum value | Yes | — |
| Add new error code | Yes | — |
| Change field type | No | Yes |
| Remove field from response | No | Yes |
| Remove endpoint | No | Yes |
| Change required parameter to optional | Yes | — |
| Change optional parameter to required | No | Yes |
| Change error code semantics | No | Yes |
| Change authentication method | No | Yes |
| Change rate limit | Yes (relax only) | Yes (tighten) |

### Webhook Versioning

- Webhook payloads include `api_version` field
- Webhook signature header: `X-NordiPass-Signature: t=..., v1=...`
- Signature scheme versioned (`v1` = HMAC-SHA256)
- Replay protection: timestamp tolerance ±5 minutes
- Idempotency: `X-Idempotency-Key` header sent by NordiPass

### Deprecation Policy
- Deprecated endpoints/fields announced 90 days before removal
- `Sunset` HTTP header on deprecated responses
- Deprecation documented in OpenAPI spec
- R2 API clients should not experience breaking changes in R3

### OpenAPI as Source of Truth
- `docs/api/openapi-v1.yaml` is authoritative API documentation
- All API changes must update the OpenAPI spec
- OpenAPI spec is validated in CI (`OpenApiSpecificationTest`)

### Problem Details (RFC 7807)
- All API errors use `application/problem+json`
- Stable error types: `validation_error`, `unauthenticated`, `forbidden`, `not_found`, `rate_limited`, `conflict`, `internal_error`
- Custom extensions for validation details

### Token Abilities (R3 Extensions)
R3 adds these abilities to the existing 12:
| Ability | Purpose |
|---------|---------|
| `analytics:read` | Read analytics data |
| `imports:write` | Execute imports |
| `webhooks:manage` | Manage webhook registrations |
| `approvals:write` | Submit/respond to approvals |

## Alternatives Considered

1. **Header-based versioning (Accept header)**: Rejected — URI-based is simpler for API consumers, easier to test with curl, and already established with `/api/v1/`.
2. **No versioning (always backward-compatible)**: Rejected — unrealistic long-term. R2 pilots may have API integrations that must not break.

## Consequences

- R3 must not break `/api/v1/` routes or response formats
- New R3 endpoints can be added under `/api/v1/catalog/` etc.
- Webhook delivery infrastructure must support signing, idempotency, retries
- OpenAPI spec grows with each R3 stage
