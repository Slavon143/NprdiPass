# NordiPass R1.11 — Catalog API

## Status

R1.11 Catalog API is implemented and integrated with the R1.10 web catalog layer.

## Base URI and versioning

The API uses URI path versioning at `/api/v1/catalog`. All routes are named with the `api.v1.catalog.` prefix.

## Authentication

All catalog API routes (except health) require a Bearer token via Laravel Sanctum.

```http
Authorization: Bearer <token>
Accept: application/json
```

Token requirements:
- Token must be valid, not expired, not revoked.
- Token must have an assigned `company_id` (issued for a specific Company).
- Token user must be active.
- Token company must be active.
- Token user must have an active membership in the token's company.

## Trusted Company Resolution

The Company is determined exclusively from the token's stored `company_id`. Request body fields (`company_id`, `company_uuid`, `tenant_id`) and query parameters never establish or override tenant context. Cookie/session authentication without a bearer token is rejected.

## Token Abilities

| Ability | Value | Allowed Operations |
|---|---|---|
| Catalog Read | `catalog.read` | GET collections, resources, search, filters, readiness, attribute values, media metadata, media content |
| Catalog Write | `catalog.write` | Create/update Categories, Products, Variants; set default Variant; manage Attribute Definitions/Options; sync attribute values |
| Catalog Lifecycle | `catalog.lifecycle` | Activate, return-to-draft, archive, restore Products/Variants/Categories/Attribute Definitions/Attribute Options |
| Catalog Media | `catalog.media` | Upload, update metadata, set primary, reorder, delete media |

Both token ability AND CompanyPermission must pass. A token ability does not replace the membership permission check.

## Ability + Permission Matrix

| Operation | Required Ability | Required Company Permission |
|---|---|---|
| Product list/show | `catalog.read` | `catalog.view` |
| Product create | `catalog.write` | `catalog.create` |
| Product update | `catalog.write` | `catalog.update` |
| Variant create/update/set-default | `catalog.write` | `catalog.update` |
| Category create/update/move/reorder | `catalog.write` | `catalog.manage_categories` |
| Attribute Definition manage | `catalog.write` | `catalog.manage_attributes` |
| Attribute Option manage | `catalog.write` | `catalog.manage_attributes` |
| Attribute value sync (Product/Variant) | `catalog.write` | `catalog.update` |
| Product activate/return-to-draft | `catalog.lifecycle` | `catalog.publish` |
| Product archive/restore | `catalog.lifecycle` | `catalog.archive` |
| Variant archive/restore | `catalog.lifecycle` | `catalog.archive` |
| Category archive/restore | `catalog.lifecycle` | `catalog.manage_categories` |
| Attribute/option archive/restore | `catalog.lifecycle` | `catalog.manage_attributes` |
| Media upload/update/delete | `catalog.media` | `catalog.manage_media` |
| Media content read | `catalog.read` | `catalog.view` |

## Rate Limits

| Limiter | Limit | Key Strategy |
|---|---|---|
| `catalog-api-read` | 120/min (default) | Token ID |
| `catalog-api-write` | 60/min (default) | Token ID |
| `catalog-api-media` | 20/min (default) | Token ID |
| `catalog-api-lifecycle` | 30/min (default) | Token ID |

All limits are configurable via `config/rate_limits.php` and environment variables.

## Request ID

Every response includes `X-Request-ID` header. The response body includes the same ID in `meta.request_id`. Invalid or missing client-provided IDs are replaced with a server-generated UUIDv4.

## Response Format

### Single Resource

```json
{
  "data": {
    "uuid": "...",
    "name": "...",
    "status": "draft"
  },
  "meta": {
    "request_id": "uuid"
  }
}
```

### Collection

```json
{
  "data": [],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  },
  "meta": {
    "request_id": "uuid",
    "current_page": 1,
    "per_page": 25,
    "total": 0,
    "last_page": 1
  }
}
```

### Error

```json
{
  "data": null,
  "meta": {
    "request_id": "uuid"
  },
  "error": {
    "code": "validation_failed",
    "message": "The request contains invalid data.",
    "details": {}
  }
}
```

## HTTP Status Codes

| Code | Meaning |
|---|---|
| 200 | Successful read/update/action |
| 201 | Successful create/upload |
| 204 | Successful delete (no-body) |
| 400 | Malformed request |
| 401 | Unauthenticated (invalid/expired/revoked token) |
| 403 | Ability or permission denied |
| 404 | Missing or wrong-tenant resource |
| 409 | Identifier/state conflict |
| 422 | Validation/readiness/media validation |
| 423 | Company suspended |
| 429 | Rate limit exceeded |
| 500 | Sanitized unexpected error |

## Stable Error Codes

| Code | Status | Meaning |
|---|---|---|
| `unauthenticated` | 401 | Missing, invalid, expired, or revoked token |
| `token_ability_missing` | 403 | Token lacks required ability |
| `forbidden` | 403 | Permission denied by policy |
| `resource_not_found` | 404 | Resource not found or concealed (wrong tenant) |
| `validation_failed` | 422 | Validation failure |
| `tenant_mismatch` | 404 | Resource belongs to another Company |
| `identifier_conflict` | 409 | Duplicate slug/SKU/GTIN/code |
| `invalid_state_transition` | 409 | Forbidden lifecycle transition |
| `activation_blocked` | 422 | Product readiness check failed |
| `media_validation_failed` | 422 | Invalid media file |
| `rate_limited` | 429 | Rate limit exceeded |
| `internal_error` | 500 | Unexpected server error |

## Pagination

Default 25 per page. Allowed values: 25, 50, 100.

## Serialization Rules

- All resources use UUIDs (never numeric internal IDs).
- Timestamps: ISO 8601 UTC with microseconds.
- Enums: string backed values.
- Decimals: string format.
- Booleans: true/false/null.
- Dates: Y-m-d format.
- No internal fields exposed: `company_id`, `created_by`, `updated_by`, `deleted_at`, normalized columns, storage paths, checksums.

## Category Endpoints

| Method | URI | Route Name | Purpose |
|---|---|---|---|
| GET | `/categories` | `categories.index` | List with status/parent/name filters |
| POST | `/categories` | `categories.store` | Create category |
| GET | `/categories/{category}` | `categories.show` | Show category |
| PATCH | `/categories/{category}` | `categories.update` | Update category fields |
| POST | `/categories/{category}/move` | `categories.move` | Move under new parent or root |
| PATCH | `/categories/reorder` | `categories.reorder` | Reorder sibling set |
| POST | `/categories/{category}/archive` | `categories.archive` | Archive category |
| POST | `/categories/{category}/restore` | `categories.restore` | Restore category |

## Product Endpoints

| Method | URI | Route Name | Purpose |
|---|---|---|---|
| GET | `/products` | `products.index` | Search, filter, paginate |
| POST | `/products` | `products.store` | Create with default Variant |
| GET | `/products/{product}` | `products.show` | Show product detail |
| PATCH | `/products/{product}` | `products.update` | Update product fields |

Product creation atomically creates a default Variant. Product deletion is not available via API.

## Product Search Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `q` | string | | Keyword search (name, slug, SKU, GTIN, MPN, brand, manufacturer) |
| `product_statuses` | array | `[draft, active]` | Filter by product statuses |
| `variant_statuses` | array | `[]` | Filter by variant statuses |
| `category_uuids` | array | `[]` | Filter by category UUIDs |
| `category_mode` | string | `primary` | `primary` or `any` |
| `include_descendants` | bool | false | Include descendant categories |
| `brand` | string | | Exact brand match |
| `manufacturer` | string | | Exact manufacturer match |
| `readiness` | string | `any` | `any`, `ready`, `not_ready` |
| `missing_data` | array | `[]` | Filter by missing data points |
| `sort` | string | `updated` | `name`, `brand`, `created`, `updated`, `variant_count`, `relevance` |
| `direction` | string | `desc` | `asc` or `desc` |
| `per_page` | int | `25` | Results per page (max 100) |
| `page` | int | `1` | Page number |

## Product Variant Endpoints

| Method | URI | Route Name | Purpose |
|---|---|---|---|
| GET | `/products/{product}/variants` | `products.variants.index` | List variants for product |
| POST | `/products/{product}/variants` | `products.variants.store` | Create variant |
| GET | `/products/{product}/variants/{variant}` | `products.variants.show` | Show variant |
| PATCH | `/products/{product}/variants/{variant}` | `products.variants.update` | Update variant |
| POST | `.../variants/{variant}/set-default` | `products.variants.set-default` | Set as default variant |

## Attribute Endpoints

| Method | URI | Route Name | Purpose |
|---|---|---|---|
| GET | `/attributes` | `attributes.index` | List definitions |
| POST | `/attributes` | `attributes.store` | Create definition |
| GET | `/attributes/{attribute}` | `attributes.show` | Show definition |
| PATCH | `/attributes/{attribute}` | `attributes.update` | Update definition |
| POST | `/attributes/{attribute}/archive` | `attributes.archive` | Archive definition |
| POST | `/attributes/{attribute}/restore` | `attributes.restore` | Restore definition |
| GET | `/attributes/{attribute}/options` | `attributes.options.index` | List options |
| POST | `/attributes/{attribute}/options` | `attributes.options.store` | Create option |
| PATCH | `/attributes/{attribute}/options/{option}` | `attributes.options.update` | Update option |
| POST | `.../options/{option}/archive` | `attributes.options.archive` | Archive option |
| POST | `.../options/{option}/restore` | `attributes.options.restore` | Restore option |
| PATCH | `/attributes/{attribute}/options/reorder` | `attributes.options.reorder` | Reorder options |

## Attribute Value Endpoints

| Method | URI | Route Name | Purpose |
|---|---|---|---|
| GET | `/products/{product}/attributes` | `products.attributes.index` | Read product attribute values |
| PUT | `/products/{product}/attributes` | `products.attributes.update` | Full sync of product attributes |
| GET | `.../variants/{variant}/attributes` | `products.variants.attributes.index` | Read variant attribute values |
| PUT | `.../variants/{variant}/attributes` | `products.variants.attributes.update` | Full sync of variant attributes |

Attribute value format uses domain-friendly serialization (e.g., select returns `{option: {id, code, label}}`).

## Media Endpoints

| Method | URI | Route Name | Purpose |
|---|---|---|---|
| GET | `/products/{product}/media` | `products.media.index` | List product media |
| POST | `/products/{product}/media` | `products.media.store` | Upload product image |
| PATCH | `/products/{product}/media/{media}` | `products.media.update` | Update metadata |
| POST | `.../media/{media}/set-primary` | `products.media.set-primary` | Set as primary image |
| PATCH | `/products/{product}/media/reorder` | `products.media.reorder` | Reorder media |
| DELETE | `/products/{product}/media/{media}` | `products.media.destroy` | Delete media |
| GET | `.../variants/{variant}/media` | `products.variants.media.index` | List variant media |
| POST | `.../variants/{variant}/media` | `products.variants.media.store` | Upload variant image |
| PATCH | `.../variants/{variant}/media/{media}` | `products.variants.media.update` | Update metadata |
| POST | `.../media/{media}/set-primary` | `products.variants.media.set-primary` | Set as primary |
| PATCH | `.../variants/{variant}/media/reorder` | `products.variants.media.reorder` | Reorder media |
| DELETE | `.../variants/{variant}/media/{media}` | `products.variants.media.destroy` | Delete media |
| GET | `/media/{media}/content` | `media.content` | Authenticated inline content delivery |

Media upload uses `multipart/form-data`. Only JPEG, PNG, and WEBP are accepted (max 10 MB).

## Lifecycle Endpoints

| Method | URI | Route Name | Purpose |
|---|---|---|---|
| GET | `/products/{product}/readiness` | `products.readiness` | Check activation readiness |
| POST | `/products/{product}/activate` | `products.activate` | Activate product |
| POST | `/products/{product}/return-to-draft` | `products.return-to-draft` | Return to draft |
| POST | `/products/{product}/archive` | `products.archive` | Archive product |
| POST | `/products/{product}/restore` | `products.restore` | Restore product |
| POST | `.../variants/{variant}/archive` | `products.variants.archive` | Archive variant |
| POST | `.../variants/{variant}/restore` | `products.variants.restore` | Restore variant |

## Tenant Isolation

All resources are tenant-scoped. Wrong-tenant UUIDs return 404 (not 403). Cross-company data never appears in listings, pagination, or search results.

## Security Headers

All API responses include:
- `X-Content-Type-Options: nosniff`
- `Cache-Control: no-store`
- `X-Request-ID`

Media content responses include:
- `Cache-Control: private`
- `Content-Type` matching stored MIME type
- `Content-Disposition: inline`

## Audit

API mutations create the same `AuditEvent` values as web mutations (e.g., `catalog.product.created`). No separate API-specific events exist.

## Optimistic Concurrency

Not implemented in R1.11. Deferred to future stage.

## Idempotency

Not implemented at the HTTP header level in R1.11. Domain-level idempotency applies: setting the current default variant, exact metadata repeats, and repeated lifecycle states are no-ops.

## Deferred to R2+

- Public anonymous Product API
- Media public URLs / CDN
- QR API
- Digital Product Passport (DPP) API
- Documents API
- PDF API
- Pricing API
- Inventory API
- Fortnox integration
- Excel/CSV import
- Bulk mutation API
- Webhooks
- GraphQL
- External search (Elasticsearch/Meilisearch)
- AI endpoints
- API SDK
- Mobile application
