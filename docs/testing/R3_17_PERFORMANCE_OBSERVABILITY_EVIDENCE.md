# R3.17 Performance and Observability Evidence

Status: prepared as the R3.17 acceptance evidence record. Update command output after each release-candidate run.

## Focused Coverage

```text
Command: php artisan test tests\Feature\Observability\PerformanceObservabilityTest.php
Exit code: 0
Tests/assertions: 5 passed / 23 assertions
Coverage:
- SLO snapshot marks stale queue lag as breached
- snapshot output does not expose queued personal data
- observability:snapshot emits machine-readable JSON
- platform observability dashboard requires platform permissions
- structured request telemetry is PII-safe and includes query profile fields
- large-catalog snapshot stays within a bounded query budget
```

## Regression Coverage

```text
Command: php artisan test tests\Feature\Audit\AuditSchemaAndLoggerTest.php --filter="request id middleware"
Exit code: 0
Tests/assertions: 1 passed / 11 assertions
Reason: verifies request-id log context still works after request telemetry middleware.

Command: php artisan test tests\Feature\Platform\PlatformOperationsTest.php
Exit code: 0
Tests/assertions: 8 passed / 30 assertions
Reason: verifies the new dashboard route does not regress R3.15 platform operations.
```

## Full Baseline

```text
Command: php artisan test
Exit code: 0
Tests/assertions: 2347 tests, 2346 passed, 1 skipped / 9491 assertions

Command: composer analyse
Exit code: 0, errors: 0

Command: vendor\bin\pint --test <R3.17 files>
Exit code: 0

Command: npm run build
Exit code: 0
```

Note: `composer test` hit Composer's 300-second process timeout in the local Windows shell. The equivalent direct command `php artisan test` completed successfully in the same source state.

## Operational Surface

- Dashboard: `GET /platform/operations/observability`
- CLI: `php artisan observability:snapshot --json`
- Config: `config/observability.php`
- Telemetry event: `observability.request`

## Alert Thresholds

Thresholds are environment-configurable:

- `OBSERVABILITY_SLO_PUBLIC_RESOLVER_P95_MS`
- `OBSERVABILITY_SLO_WEB_P95_MS`
- `OBSERVABILITY_SLO_QUEUE_LAG_SECONDS`
- `OBSERVABILITY_SLO_FAILED_JOBS`
- `OBSERVABILITY_SLO_STORAGE_FREE_PERCENT`
- `OBSERVABILITY_SLOW_QUERY_MS`
- `OBSERVABILITY_SLOW_REQUEST_MS`

## Privacy Notes

Telemetry logs route templates and aggregate timings only. Raw payloads, query strings, SQL bindings, queued payloads, emails, tokens, cookies, and authorization headers are intentionally excluded.
