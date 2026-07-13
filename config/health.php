<?php

return [
    'enabled' => filter_var(env('HEALTH_ENABLED', true), FILTER_VALIDATE_BOOL),

    'details' => filter_var(env('HEALTH_DETAILS', false), FILTER_VALIDATE_BOOL),

    'require_database' => filter_var(env('HEALTH_REQUIRE_DATABASE', true), FILTER_VALIDATE_BOOL),

    'require_cache' => filter_var(env('HEALTH_REQUIRE_CACHE', true), FILTER_VALIDATE_BOOL),

    'require_queue' => filter_var(env('HEALTH_REQUIRE_QUEUE', true), FILTER_VALIDATE_BOOL),

    'require_scheduler' => filter_var(env('HEALTH_REQUIRE_SCHEDULER', false), FILTER_VALIDATE_BOOL),

    'scheduler_max_age' => (int) env('HEALTH_SCHEDULER_MAX_AGE', 180),

    'require_async_queue' => filter_var(env('HEALTH_REQUIRE_ASYNC_QUEUE', false), FILTER_VALIDATE_BOOL),

    'scheduler_heartbeat_key' => 'nordipass:infrastructure:scheduler:last_run',
];
