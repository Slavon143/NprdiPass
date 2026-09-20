# R3.12 — QR and Label Management

**Status:** ACCEPTED — QR/label foundation closed; PDF/ZIP/batch export remains follow-up scope
**Date:** 2026-09-19

## Scope closed

R3.12 extends the existing stable QR workflow with printable label management:

1. Stable QR payload remains `{PASSPORT_PUBLIC_BASE_URL}/p/{publicId}`.
2. Existing SVG and PNG downloads remain deterministic, cacheable, and tenant-scoped.
3. QR management page now exposes a print label action.
4. New printable HTML label page renders QR code, product name, public URL, target status, and print guidance.
5. QR print specification is centralized in `PassportQrPrintSpec`.
6. Print spec includes quiet zone modules, error correction, foreground/background colors, contrast ratio, and recommended physical sizes.
7. Contrast ratio is computed from configured QR colors and flags low-contrast combinations.
8. QR page surfaces the same spec to operators before packaging/printing.

## Public compatibility

R3.12 does not change public Passport URL compatibility:

- QR route target remains `/p/{publicId}`.
- Existing generated SVG/PNG QR codes continue to resolve to the same public target.
- Published/unpublished/archived lifecycle behavior remains unchanged.

## Routes

- `GET /catalog/products/{product}/passport/qr`
- `GET /catalog/products/{product}/passport/qr.svg`
- `GET /catalog/products/{product}/passport/qr.png`
- `GET /catalog/products/{product}/passport/qr/label`

## Stage gates

| Gate | Evidence |
|------|----------|
| Stable resolver | QR payload factory tests and lifecycle tests |
| SVG/PNG generation | Renderer, download, cache, and security tests |
| Quiet-zone/print guidance | QR page and label tests |
| Contrast validation | `PassportQrPrintSpec` unit tests |
| Tenant isolation | Existing QR page/download/security tests |

## Validation commands

```bash
php artisan test tests\Unit\Passports\Qr\PassportQrRendererTest.php tests\Unit\Passports\Qr\PassportQrPayloadFactoryTest.php tests\Feature\Passports\Qr\PassportQrPageTest.php tests\Feature\Passports\Qr\PassportQrDownloadTest.php tests\Feature\Passports\Qr\PassportQrCacheTest.php tests\Feature\Passports\Qr\PassportQrLifecycleTest.php tests\Feature\Passports\Qr\PassportQrSecurityTest.php
php artisan route:list --path=qr
vendor\bin\pint --test app\Data\Passports\Qr\PassportQrViewModel.php app\Http\Controllers\Catalog\ProductPassportQrController.php app\Services\Passports\Qr\PassportQrPrintSpec.php resources\views\passports\qr\show.blade.php resources\views\passports\qr\label.blade.php routes\web.php tests\Unit\Passports\Qr\PassportQrRendererTest.php tests\Feature\Passports\Qr\PassportQrPageTest.php
composer analyse
```

## Follow-up scope

1. PDF label generation.
2. Batch label generation and ZIP downloads.
3. Automated QR decode verification using an approved decoder dependency or service.
4. Optional branding/logo constraints with decode safety tests.
