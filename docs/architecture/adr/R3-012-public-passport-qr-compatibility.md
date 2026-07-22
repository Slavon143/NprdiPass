# ADR-R3-012 — Public Passport and QR Compatibility

**Status:** ACCEPTED
**Date:** 2026-07-19
**Stage:** R3.1
**Supersedes:** None

---

## Context

R2 established public passport URLs at `/p/{public_id}` and QR codes that encode these URLs. R3 must not break any existing published passport URL or QR code, even as the public passport UI is rebuilt for v2.

## Decision

### URL Compatibility

| Contract | R2 URL | R3 URL | Compatibility |
|----------|--------|--------|:---:|
| Public passport page | `/p/{public_id}` | `/p/{public_id}` | Identical |
| Public asset (media) | `/p/{public_id}/media/{asset}` | `/p/{public_id}/media/{asset}` | Identical |
| Public asset (document) | `/p/{public_id}/documents/{asset}` | `/p/{public_id}/documents/{asset}` | Identical |
| QR payload | `/p/{public_id}` | `/p/{public_id}` | Identical |

### QR Compatibility

- QR codes generated in R2 encode `/p/{public_id}` where `public_id` is a UUIDv7
- R3 QR codes MUST encode the same format: `/p/{public_id}`
- No version parameter in QR URL — the latest published version is always shown
- R3 may add QR style options, but the encoded URL format is fixed

### Public Passport UI

- R3.11 introduces a new mobile-first public passport page
- Old URL pattern continues to work — the new UI is served at the same URLs
- Content comes from the immutable published snapshot (same as R2)
- No new URL patterns introduced that would invalidate R2 QR codes

### Published Snapshots

- R2 published snapshots remain accessible and unchanged
- R3 published snapshots may have additional data (new DPP sections)
- Public passport controller determines available data from snapshot schema version
- Missing sections (from older schema versions) are gracefully omitted or shown as "not available"

### Historical Version Access

- R2 policy: historical versions NOT publicly accessible
- R3 policy: same — only the current published version is shown at `/p/{public_id}`
- R4 may add optional historical version browsing (deferred)

### Language Routing

- R2: default language from `product_passports.default_language`
- R3: same — language selector on public page, default from passport config
- URL pattern: `/p/{public_id}?lang=sv` (query parameter, not path segment)
- SEO: `<link rel="alternate" hreflang="...">` for each enabled language

## Alternatives Considered

1. **Version-specific public URLs (`/p/{public_id}/v/{version}`)**: Rejected for R3 — adds complexity without clear user benefit. Deferred to R4.
2. **Language in URL path (`/p/{public_id}/sv`)**: Rejected — requires QR code regeneration for each language. Query parameter is simpler and doesn't invalidate existing QR codes.

## Consequences

- R2 QR codes printed on products continue working through R3
- R3.11 must render at the same URL patterns as R2.7
- PublicPassportController in R3 serves both old and new snapshot schemas
- No migration needed for public URLs or QR codes
