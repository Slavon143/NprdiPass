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

API writes revalidate the authenticated credential and every route-required ability inside
each persistence transaction using current locking reads. The check binds the token to
its original credential hash, user and company, and validates active user/company,
membership and expiration. It follows Company → User → membership → token lock order.
A revocation committed before that boundary rejects the write with the existing error
codes. A transaction already authorized may finish; revocation waits for its locks to
be released, and a subsequent transaction must validate again. Expiration is evaluated
at that authorization boundary, rather than treated as cancellation of an accepted write.
Read requests retain their preflight checks. Already committed work, including queued
jobs, is not canceled by token revocation; worker actor/tenant authorization still applies.

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

Import mapping targets are `name`, `slug`, `short_description`, `description`, `brand`, `manufacturer`, `sku`, `gtin`, `mpn`, `primary_category` and `attribute:<code>` for active product-compatible attributes in the current company. Unsupported or unavailable targets return a validation error before replacing the persisted preview. Status changes, timestamps and document references are not import targets. Unmapped source columns remain in the saved source/preview and are not applied.

Source metadata has an explicit boundary: new products start as `draft`; updates preserve their current status and cannot restore archived products. Product activation, return to draft, archive and restore use their dedicated authorized lifecycle operations. Source `updated_at` is retained in persisted `source_data`, but does not replace NordiPass timestamps and is not a freshness watermark or stale-update guard. Older source rows may still update mapped fields. `document_ref` is retained as source text: it does not fetch URLs, attach files, create document versions or approve evidence. Upload documents through the separate document workflow. The web preview lists source headers not consumed by the saved mapping. API consumers can derive that list from `headers` minus mapping values; records expose `mapped_data`, while raw `source_data` remains persisted internally.

SKU, GTIN and MPN target the product's default variant. Products are still matched by normalized slug; an identifier does not replace this matching key. SKU uses the ordinary variant normalization and is unique within the company, as is checksum-valid GTIN. GTIN remains text, including leading zeros; MPN is not unique. Preview detects duplicates across batches and existing secondary variants. Apply uses database uniqueness and per-row transactions to roll back the complete row on a later conflict. An unmapped identifier is preserved on update; a mapped blank clears it. Export includes these identifiers: XLSX uses text cells, while CSV retains formula-injection escaping, so values starting with formula characters may gain a protective apostrophe.

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

## DPP enrollment

`PATCH /api/v1/catalog/products/{product}/managed` accepts a required boolean
`managed` and requires `catalog.write` plus the company's catalogue-update permission.
The `Product` response includes `managed`. Tenant UUID resolution, subscription
admission and the managed-slot check run through the transactional enrollment action.
Repeated requests for the same state are idempotent. A full quota returns the stable
422 validation envelope. Ordinary product create/update fields cannot change enrollment.
Removing enrollment retains published history; unmanaged products cannot recalculate
DPP readiness, create a DPP draft or publish a new version. Archive releases the slot
without releasing retained physical storage.

For commercial v1 tenants, webhook configuration and delivery additionally require
`webhooks.enabled` and a writable subscription. Paused pending deliveries keep their
event identity and attempt count; recovery retries admission after access is restored.

## Webhook delivery semantics

Domain mutations enqueue subscribed events transactionally for active endpoints
of the same company. Outbound `data` fields are:

| Event | Fields |
|---|---|
| `passport.published` | `passport_uuid`, `published_version_uuid`, `version_number` |
| `passport.unpublished` | `passport_uuid`, `published_version_uuid` |
| `document.approved` | `document_uuid`, `version_uuid` |
| `import.completed` | `import_job_uuid`, `processed_rows`, `created_rows`, `updated_rows`, `skipped_rows` |
| `billing.subscription.updated` | `status`, `plan_key` on confirmed subscription updates |

Delivery starts after the outer transaction commits. `webhooks:recover` requeues
stale committed pending deliveries and due retries. Atomic attempt claims use
a lease and persist a five-attempt budget. Delivery is at-least-once: consumers
must deduplicate with the stable event ID/idempotency key. A worker interruption
after the receiver accepts a request can still cause a repeated delivery.
Secrets, document bytes, and Stripe customer/subscription identifiers are absent
from these domain payloads. HTTPS destinations and each redirect are validated;
the cURL connection is pinned to a validated public IP.

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

## DPP applicability and public data

The native authenticated passport UI also provides
`GET /catalog/products/{product}/passport/registry` and
`POST /catalog/products/{product}/passport/registry/prepare`. These are session/CSRF
routes, not API v1 endpoints. Preparation requires `passports.manage`, a writable
company and a managed, unarchived product; it accepts a current published-version
UUID and `acceptance` or `production` environment. It creates an immutable local
record with status `blocked`, never an EU registration. The UI offers acceptance
only. No external submission occurs in either environment. A replay for the same
version/environment returns the original record and hosted URL; republishing
creates a separate record. The published snapshot and public JSON are unaffected.

The 71-position battery catalogue is advisory mapping metadata from EU publication
edition 2.0, August 2026. Candidate presence is not compliance evidence. Guidance
deferrals do not alter legal applicability, and restricted-data domains do not
grant permissions. The catalogue is internal; it is not added to the public JSON
or represented as an official registry submission schema.

Passport settings additionally accept `regulatory_context` and an explicit boolean
`refresh_profile`. Both mutations require the current `expected_revision`, tenant
authorization and a managed editable passport; they advance the draft revision.
Classification records the target market, product type, industrial battery
capacity, model/item identifiers, market/lifecycle event and date, and
classification evidence. Unknown context fields are rejected. It is
operator-declared scope evidence, not certification.

`refresh_profile: true` freezes currently assigned profiles and requirements.
Stored evaluations and published versions are never rewritten. A changed,
unavailable or unfrozen regulatory profile creates the
`regulatory.profile.current` blocker during direct readiness and publication.
Operational `nordipass-pilot` history retains its compatibility behavior. Product
list batches do not perform this live check; direct readiness and publication do.

Requirement application dates are inclusive. Readiness rule `safe_context`
retains `requirement_source_version`, `requirement_effective_from`,
`requirement_effective_to` and `applicability_checked_on`. Future/ended
requirements are `not_applicable`; a missing handler becomes a blocker when
the requirement applies. Application dates cannot disable integrity blockers.

`profile_details.regulatory_scope` identifies scope, date, legal source and
whether EU battery registration is required (`null` when not determined).
EV/LMT and industrial batteries strictly above 2 kWh are upcoming before
2027-02-18. From that date, declared
covered batteries receive `regulatory.battery.coverage`: full Annex XIII,
restricted access and registry integration have not been implemented/accepted.
Warning acknowledgement does not bypass it. Electrical classification requires
a separate sector-act assessment. These additions preserve existing API error
envelopes and validation/error codes.

Earlier battery market events are distinguished from later remanufacturing or
repurposing events. A missing event date cannot establish a retrospective exemption:
from 2027-02-18 it creates an assessment blocker for covered battery categories.
Classification evidence remains operator-declared and needs independent acceptance.

The public passport URI `/p/{publicId}` also supports `Accept: application/json`.
It returns `nordipass-public-passport-v1`, the allowlisted public data from the
current published snapshot, including public documents/media links and effective
language. It never returns draft payloads, tenant IDs, internal classification
evidence, readiness or storage keys. JSON and HTML have separate ETags and
`Vary: Accept, Accept-Encoding`. The route shares the existing cookie stripping,
throttle and published-only lifecycle policy. It is outside API v1 and does not
use the authenticated API envelope. This is a NordiPass interchange format, not
the EU Registry semantic schema or evidence of EN 18222 conformity.

## Readiness execution provenance

Readiness `profile_details.execution` identifies the code that performed the
evaluation: application source digest, Composer lock digest, PHP version and
actual rule handler classes/source digests. The configuration
`rule_set_fingerprint` remains a separate historical contract.
`draft_execution` contains provenance captured when a new draft was created;
`execution_comparison` is `matched`, `changed`, or `legacy_unknown`.
Legacy drafts have no fabricated execution identity. Manifests declare
`replay_guarantee: provenance_only`; historical PHP replay requires retained
release artifacts. Validation evidence preserves each manifest and its results.
