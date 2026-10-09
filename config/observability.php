<?php

return [
    /*
     * Lightweight observability for R3.17. The app emits structured, PII-safe
     * request telemetry without requiring a proprietary APM dependency.
     */
    'request_logging_enabled' => filter_var(env('OBSERVABILITY_REQUEST_LOGGING_ENABLED', true), FILTER_VALIDATE_BOOL),

    'slow_query_ms' => (int) env('OBSERVABILITY_SLOW_QUERY_MS', 100),

    'slow_request_ms' => (int) env('OBSERVABILITY_SLOW_REQUEST_MS', 1000),

    'slo' => [
        'public_resolver_p95_ms' => (int) env('OBSERVABILITY_SLO_PUBLIC_RESOLVER_P95_MS', 750),
        'web_p95_ms' => (int) env('OBSERVABILITY_SLO_WEB_P95_MS', 1000),
        'queue_lag_seconds' => (int) env('OBSERVABILITY_SLO_QUEUE_LAG_SECONDS', 300),
        'failed_jobs' => (int) env('OBSERVABILITY_SLO_FAILED_JOBS', 0),
        'storage_free_percent' => (int) env('OBSERVABILITY_SLO_STORAGE_FREE_PERCENT', 10),
    ],

    'storage_path' => env('OBSERVABILITY_STORAGE_PATH', storage_path()),

    'resolver_metrics' => [
        'enabled' => filter_var(env('OBSERVABILITY_RESOLVER_METRICS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'store' => env('OBSERVABILITY_RESOLVER_METRICS_STORE', env('CACHE_STORE', 'database')),
    ],
];
