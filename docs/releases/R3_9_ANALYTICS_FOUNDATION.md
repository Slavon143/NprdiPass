# R3.9 — QR and Public Passport Analytics Foundation

**Status:** ACCEPTED — foundation closed; dashboard/reporting UI remains follow-up scope
**Date:** 2026-09-19

## Scope closed

R3.9 implements privacy-safe ingestion and aggregation for public Passport analytics:

1. Public Passport page views enqueue `passport_view` analytics events after successful 200 responses.
2. Cached 304 responses return before analytics dispatch and do not create view events.
3. Event storage includes tenant/product/passport/version identifiers plus country, device, referrer category, bot flag, and timestamp.
4. Event storage intentionally excludes raw IP addresses, full User-Agent strings, full referrer URLs, cookies, session ids, and personal identifiers.
5. Device, referrer, country, and bot classification runs at request time; only the reduced categories are passed to the queue job.
6. Daily and monthly aggregate tables are rebuilt idempotently from raw events.
7. Bot events are retained for short diagnostics but excluded from aggregates.
8. Duplicate public Passport events for the same passport/country/device/event type inside the configured dedupe window are counted once in aggregates.
9. Retention command prunes raw non-bot events, bot events, and expired daily aggregates.
10. Scheduler entries run analytics aggregation hourly and retention daily.

## Privacy model

The analytics schema is intentionally coarse:

| Stored field | Notes |
|--------------|-------|
| `company_id`, `product_id`, `published_version_id`, `passport_public_id` | Tenant/product/version attribution for reporting |
| `event_type` | `passport_view` now; `qr_scan` is reserved for QR resolver instrumentation |
| `country_code` | Two-letter country from trusted edge headers when available, otherwise `XX` |
| `device_category` | `desktop`, `mobile`, `tablet`, or `unknown` |
| `referrer_category` | `direct`, `internal`, `search`, `social`, `external`, or `unknown` |
| `bot_flag` | Used to exclude crawler/internal-like traffic from aggregates |
| `occurred_at` | Event timestamp |

Forbidden fields are not present on `analytics_events`: raw IP, full User-Agent, and full referrer URL.

## Commands and retention

- `analytics:aggregate {--date=} {--month=}` rebuilds daily and monthly aggregates.
- `analytics:prune` applies raw/bot/daily retention windows.
- `.env.example` documents `FEATURE_ANALYTICS`, `ANALYTICS_RAW_RETENTION_DAYS`, `ANALYTICS_BOT_RETENTION_DAYS`, `ANALYTICS_DAILY_RETENTION_DAYS`, and `ANALYTICS_DEDUPE_SECONDS`.

Default retention:

- Raw non-bot events: 90 days.
- Bot events: 30 days.
- Daily aggregates: 1095 days.
- Monthly aggregates: kept indefinitely.

## Validation commands

```bash
php artisan migrate --force
php artisan test tests\Feature\Analytics\AnalyticsFlowTest.php
php artisan test tests\Feature\Passports\Public\PublicPassportCacheTest.php --filter=if_none_match
php artisan schedule:list
vendor\bin\pint --test app\Console\Commands\AnalyticsAggregateCommand.php app\Console\Commands\AnalyticsPruneCommand.php app\Enums\Analytics app\Http\Controllers\Passports\PublicPassportController.php app\Jobs\Analytics app\Models\Analytics app\Services\Analytics config\analytics.php config\features.php database\migrations\2026_09_19_000001_create_analytics_foundation_tables.php tests\Feature\Analytics\AnalyticsFlowTest.php
composer analyse
```

## Follow-up scope

1. Wire QR scan events once the QR resolver/label workflow has a scan endpoint distinct from ordinary public page views.
2. Add tenant-facing analytics dashboard/export UI on top of the aggregate tables.
3. Add load baselines once public beta traffic assumptions are confirmed.
