# R2.7 — Public Product Passport Page

## Overview

The public passport page provides an unauthenticated, read-only view of a published Digital Product Passport. All data comes from the immutable snapshot stored at publication time — never from live models.

## Public URL

```
GET /p/{public_id}
```

`public_id` is a UUIDv7 stored in `product_passports.public_id`. It is:
- Generated once at passport creation
- Immutable across publications
- Never reused

## Architecture

```
User → GET /p/{public_id}
     → PublicPassportController
       → PublicPassportResolver
         → ProductPassport (by public_id)
         → current_published_version.payload (immutable snapshot)
         → PublicPassportViewModel (typed, no raw snapshot access)
       → View (passports.public.show)
```

### Snapshot-only Rule

The public page reads **only** from the published version's `payload` column. This column contains:

- `enabled_sections` — active DPP sections
- `data` — non-translatable field values
- `translations` — translatable field values by locale
- `document_references` — pinned document version UUIDs
- `_catalog_context` — frozen product, variant, media, and document metadata

**Forbidden in public renderer:**
- `$product->name`, `$product->variants`, `$product->media`, `$product->documents`
- `$passport->currentDraftVersion`
- `$passport->currentDraft`
- Readiness evaluator
- Live database queries to `products`, `product_documents`, `product_media`

## Lifecycle

| Passport State | HTTP | Behavior |
|---------------|------|----------|
| Never published | 404 | Does not reveal passport existence |
| Published | 200 | Shows current version |
| Unpublished | 404 | Stable URL stops working |
| Archived | 404 | |
| Restored (draft) | 404 | Must be republished to show |
| After V2 publish | 200 | Same URL shows V2; V1 not accessible publicly |

## Page Sections

All sections are derived from DPP enabled sections in the snapshot:

1. Product hero (image, name, brand, description)
2. Gallery (additional images, lazy loaded)
3. Quick facts (GTIN, SKU, country, manufacturer, version, date)
4. Product identity
5. Manufacturer & responsible operator
6. Origin & traceability
7. Materials & composition (with percentages, recycled content, hazards)
8. Safety (visually prominent, warning icons)
9. Usage & care
10. Repair & spare parts
11. Recycling & disposal (visually prominent)
12. Environmental information (with disclaimer)
13. Certifications & documents (downloadable public PDFs)
14. Support & contact
15. Publication details
16. Legal disclaimer

Empty sections are automatically hidden.

## Document Delivery

Documents are served through:

```
GET /p/{public_id}/documents/{version_uuid}
```

- Only `visibility: passport_public` documents are accessible
- Files are read from `product_documents` disk
- Sanitized filename in `Content-Disposition`
- Immutable cache headers (public, max-age=31536000, immutable)
- ETag based on SHA-256 checksum
- `X-Content-Type-Options: nosniff`

## Media Delivery

Media images are served through:

```
GET /p/{public_id}/media/{media_uuid}
```

- Read from `catalog_media` disk using stored `storage_path`
- Inline Content-Disposition
- Immutable cache headers
- ETag support

## Security

- All output uses Blade escaping (`{{ }}`, never `{!! !!}` except JSON-LD)
- `javascript:` and other non-http/https URL schemes are rejected by normalizer
- Storage paths never exposed in HTML
- No direct filesystem paths in URLs
- CSP-compatible (no inline scripts beyond JSON-LD)
- Rate limited:
  - Page: `public-passport` (60/min per IP)
  - Assets: `public-passport-assets` (120/min per IP)
- Security headers: `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `X-Frame-Options`

## HTTP Caching

- Page: `ETag` (snapshot checksum), `Last-Modified` (published_at), `Cache-Control: public, max-age=3600`
- Assets: `Cache-Control: public, max-age=31536000, immutable`
- Conditional GET with 304 Not Modified

## SEO

- `<title>` includes product name
- `<meta name="description">` from public description
- Canonical URL `/p/{public_id}`
- Open Graph: title, description, image
- Twitter card: summary_large_image
- `robots: index, follow` for published; 404 has implicit noindex
- JSON-LD `Product` schema with name, brand, manufacturer, SKU, GTIN, image, category

## Performance

- No readiness evaluator calls
- No live Product queries
- No live Document queries (document data frozen in `_catalog_context`)
- No checksum recalculation
- Bounded query count (passport + version lookup only)

## Admin Integration

- **Published state**: Shows "Open Public Page" link and "Copy public link" button
- **Editor**: Shows "Open Public Page" alongside version links when published
- **Unpublished/Archived**: Public link hidden

## Key Files

### Controllers
- `app/Http/Controllers/Passports/PublicPassportController.php`
- `app/Http/Controllers/Passports/PublicPassportAssetController.php`

### Services
- `app/Services/Passports/Public/PublicPassportResolver.php`

### Data Transfer Objects
- `app/Data/Passports/Public/PublicPassportViewModel.php`
- `app/Data/Passports/Public/PublicPassportDocument.php`
- `app/Data/Passports/Public/PublicPassportMedia.php`

### Views
- `resources/views/layouts/public-passport.blade.php`
- `resources/views/passports/public/show.blade.php`
- `resources/views/passports/public/partials/*`

### Tests
- `tests/Feature/Passports/Public/PublicPassportPageTest.php`
- `tests/Feature/Passports/Public/PublicPassportSnapshotIsolationTest.php`
- `tests/Feature/Passports/Public/PublicPassportLifecycleTest.php`
- `tests/Feature/Passports/Public/PublicPassportAssetTest.php`
- `tests/Feature/Passports/Public/PublicPassportSecurityTest.php`
- `tests/Feature/Passports/Public/PublicPassportCacheTest.php`
- `tests/Feature/Passports/Public/PublicPassportPerformanceTest.php`

## R2.8 / R2.9 Exclusions

Not implemented in R2.7:
- QR codes for public passport URLs
- Multilingual passport authoring
- Public version history endpoints
- Language switcher on public page

The architecture supports adding these in R2.8/R2.9 without changing the stable public URL format.
