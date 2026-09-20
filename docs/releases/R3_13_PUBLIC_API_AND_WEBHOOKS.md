# R3.13 Public API and Webhooks

R3.13 adds the outbound webhook foundation to the existing `/api/v1` contract.

## Implemented

- Added `webhooks.manage` as a dedicated Sanctum token ability.
- Added tenant-scoped `webhook_endpoints` and `webhook_deliveries` tables.
- Added encrypted endpoint secrets, subscribed event types, active/disabled status, and delivery history.
- Added API endpoints:
  - `GET /api/v1/webhooks/endpoints`
  - `POST /api/v1/webhooks/endpoints`
  - `PATCH /api/v1/webhooks/endpoints/{endpoint}`
  - `POST /api/v1/webhooks/endpoints/{endpoint}/disable`
  - `GET /api/v1/webhooks/deliveries`
- Added delivery service and `webhooks` queue job.
- Added HMAC signatures with `NordiPass-Signature: t=<unix>,v1=<sha256-hmac>`.
- Added `NordiPass-Event-ID` and `Idempotency-Key` headers for consumers.
- Added replay-tolerance verification helper.
- Updated `docs/API.md`, `docs/api/openapi-v1.yaml`, `.env.example`, and README queue guidance.

## Security boundaries

- Endpoint secrets are encrypted at rest and never returned in API responses.
- All endpoint and delivery queries are scoped to the token company.
- Webhook management requires `webhooks.manage`; other API abilities do not imply it.
- Delivery payload signing uses the raw JSON body and endpoint secret.
- Replay verification rejects stale timestamps according to `WEBHOOK_SIGNATURE_TOLERANCE_SECONDS`.

## Verification

- `php artisan migrate --force`
- `php artisan test tests\Feature\Api\V1\Webhooks\WebhookApiTest.php`
- `php artisan test tests\Feature\Api\V1\Webhooks\WebhookApiTest.php tests\Unit\Webhooks\WebhookSignatureTest.php`
- `php artisan route:list --path=webhooks`
- `vendor\bin\pint --test ...`
- `composer analyse`
