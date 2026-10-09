# R3.17 Performance and Observability

R3.17 closes the performance and observability prerequisite for R3.19 by adding lightweight, vendor-neutral telemetry, SLO snapshots, queue lag monitoring, storage checks, query profiling, and large-catalog baseline coverage.

## Implemented

- Added PII-safe structured request telemetry through `RecordRequestTelemetry`.
  - Emits request ID, method, route name, route template, status, duration, query count, total query time, and slow-query count.
  - Does not log request payloads, emails, tokens, cookies, authorization headers, query strings, or raw SQL/bindings.
- Added `RequestTelemetry` singleton with one database query listener registered in `AppServiceProvider`, avoiding listener accumulation in long-running workers.
- Added `config/observability.php` for SLO thresholds:
  - public resolver p95 latency placeholder
  - web p95 threshold
  - queue lag threshold
  - failed-job threshold
  - storage free-space threshold
- Added `ObservabilitySnapshotService` with:
  - queue pending/failed/tracked job counts and oldest pending age
  - public resolver counters and latency slot for APM ingestion
  - storage free/total bytes and free percentage
  - catalog scale baseline and large-catalog company count
- Added `php artisan observability:snapshot --json` for machine-readable operational checks.
- Added platform operations observability dashboard at `GET /platform/operations/observability`, protected by platform access and audit-view permissions.
- Added focused R3.17 tests for SLO breaches, PII-safe telemetry, platform dashboard access, CLI JSON, and large-catalog query budget.

## Security and privacy

- Telemetry is intentionally low-cardinality and PII-safe.
- Routes are logged as route names/templates, not raw URLs.
- The service never decodes queued job payloads, so queued invitation URLs, emails, OAuth errors, or webhook payloads are not exposed in snapshots.
- Platform dashboard access remains separate from tenant membership and requires explicit platform permissions.

## Deferred to later stages

- External APM, distributed tracing backend, and production dashboard provisioning are environment work, not a product dependency.
- Real p95 resolver timing requires time-series ingestion in staging/production. R3.17 exposes the contract and local baseline slot; R3.19 records final performance evidence.

## Verification

- `php artisan test tests\Feature\Observability\PerformanceObservabilityTest.php`
- `php artisan route:list --path=platform/operations`
- `php artisan observability:snapshot --json`
- `vendor\bin\pint --test ...`
- `composer analyse`
- `composer test`
- `npm run build`
