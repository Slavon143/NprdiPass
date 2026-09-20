# R3.11 — Public Passport v2

**Status:** ACCEPTED — public rendering hardening closed; broader visual redesign and approval inbox remain follow-up scope
**Date:** 2026-09-19

## Scope closed

R3.11 hardens the public Passport surface while preserving the stable `/p/{publicId}` route contract:

1. Public Passport responses keep the existing stable URL and immutable published-version resolver.
2. Public Passport pages now emit privacy/security headers:
   - `X-Robots-Tag: index, follow`
   - `X-Content-Type-Options: nosniff`
   - `Referrer-Policy: strict-origin-when-cross-origin`
   - restrictive `Permissions-Policy`
3. Public Passport routes strip `Set-Cookie` headers so the public page does not create a session/tracking cookie.
4. 304 cache responses preserve the same SEO/privacy header contract and remain no-cookie.
5. The public layout now includes explicit v2 marker, skip link, banner/main/contentinfo landmarks, and focusable main content.
6. Mobile-first section navigation provides quick access to overview, quick facts, documents, and publication details.
7. Public privacy notice explains anonymous aggregate analytics and explicitly excludes raw IP, full User-Agent, and full referrer URL storage.
8. Existing canonical, hreflang, Open Graph, JSON-LD, mobile viewport, immutable snapshot, and no-admin-link behaviours remain covered by tests.

## Public contract

The public Passport remains unauthenticated and read-only:

- Page route: `GET /p/{publicId}`
- Media route: `GET /p/{publicId}/media/{asset}`
- Document route: `GET /p/{publicId}/documents/{asset}`

The page reads from `ProductPassport::currentPublishedVersion` through `PublicPassportResolver`. Mutable draft/catalog rows are not used for already published content.

## Stage gates

| Gate | Evidence |
|------|----------|
| Stable public URL | Routes remain under `/p/{publicId}` |
| Immutable published reads | Existing public resolver and snapshot isolation tests |
| Mobile/accessibility baseline | Landmarks, skip link, v2 marker, sticky section navigation tests |
| SEO baseline | Canonical, hreflang, Open Graph, JSON-LD tests |
| Privacy/no-cookie | No `Set-Cookie` on 200/304 public page responses; privacy notice present |
| Cache compatibility | 304 tests verify headers and no cookie |

## Validation commands

```bash
php artisan test tests\Feature\Passports\Public\PublicPassportPageTest.php tests\Feature\Passports\Public\PublicPassportCacheTest.php tests\Feature\Passports\Public\PublicPassportLanguageSeoTest.php
php artisan route:list --path=p
vendor\bin\pint --test app\Http\Controllers\Passports\PublicPassportController.php app\Http\Middleware\StripPublicPassportCookies.php bootstrap\app.php resources\views\layouts\public-passport.blade.php resources\views\passports\public\show.blade.php tests\Feature\Passports\Public\PublicPassportPageTest.php tests\Feature\Passports\Public\PublicPassportCacheTest.php routes\web.php
composer analyse
```

## Follow-up scope

1. Browser screenshot evidence across mobile/tablet/desktop once the visual pass is ready.
2. Richer variant-selection UI when variant snapshots are expanded.
3. Dedicated performance baseline for public resolver latency under expected public beta traffic.
