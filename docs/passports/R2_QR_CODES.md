# R2.8 — QR Codes

## Overview

R2.8 implements stable QR codes for Product Passports. Each passport has one canonical QR code that encodes the stable `/p/{public_id}` URL. The QR is available from the moment the passport is created (stable public_id is generated) and never changes throughout the passport lifecycle.

## QR Identity Contract

- One passport → one `public_id` → one canonical public URL → one QR payload
- Publishing Version 2 does not change the QR
- Unpublishing does not change the QR
- Archiving does not change the QR
- The QR payload contains only the canonical public URL

## Configuration

```dotenv
PASSPORT_PUBLIC_BASE_URL="${APP_URL}"
```

Default: `config('app.url')`

In `config/passports.php`:

```php
'public_base_url' => env('PASSPORT_PUBLIC_BASE_URL', env('APP_URL')),
'qr' => [
    'renderer_version' => '1',
    'error_correction' => 'medium',
    'preview_size' => 280,
    'download_size' => 1024,
    'quiet_zone' => 4,
    'foreground' => '#000000',
    'background' => '#ffffff',
    'print' => [
        'min_recommended_size_mm' => 25,
        'recommended_packaging_size_mm' => 30,
    ],
],
```

## Canonical URL

QR payload is built from trusted config only:

```
{PASSPORT_PUBLIC_BASE_URL}/p/{public_id}
```

The payload ignores request `Host` header, `Referer`, and any user input.

## QR Formats

- **SVG** — Vector format for printing and scaling. Content-Type: `image/svg+xml`
- **PNG** — Binary format. Minimum 1024×1024 px. Content-Type: `image/png`

## Caching & ETag

- Cache key: `passport-qr:{renderer_version}:{public_id}:{format}`
- ETag: MD5 of renderer version + public_id + format + base URL
- Conditional requests (`If-None-Match`) return 304
- Cache-Control: `public, max-age=86400`

## Admin Routes

| Method | Path | Name | Permission |
|--------|------|------|------------|
| GET | `/catalog/products/{product}/passport/qr` | `passport.qr.show` | PassportsView |
| GET | `/catalog/products/{product}/passport/qr.svg` | `passport.qr.svg` | PassportsView |
| GET | `/catalog/products/{product}/passport/qr.png` | `passport.qr.png` | PassportsView |

## QR Lifecycle

| State | QR Available | QR Stable | Target HTTP |
|-------|-------------|-----------|-------------|
| Draft (never published) | Yes | Yes | 404 |
| Published | Yes | Yes | 200 |
| Unpublished | Yes | Yes | 404 |
| Archived | Yes | Yes | 404 |
| Republished | Yes (same) | Yes | 200 |

## Security

- SVG contains no `<script>`, `foreignObject`, or external references
- Filenames sanitized to prevent CRLF injection and path traversal
- Host header injection ignored
- No database IDs or storage paths in responses
- No product name in SVG as raw XML
- Permission-checked via `PassportsView`

## Printing Guidance

Admin page shows:
- Minimum recommended size: 25×25 mm
- Recommended packaging: 30×30 mm+
- Keep quiet zone, don't stretch, don't crop
- Test printed sample before mass printing

## QR Library

Uses `endroid/qr-code` v6 with `bacon/bacon-qr-code`. Locally rendered — no external API calls.
