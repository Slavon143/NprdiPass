<?php

return [
    // Disabled by default in deployed environments; local/testing stay usable.
    'production_imports' => env(
        'FEATURE_PRODUCTION_IMPORTS',
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
    ),
    'fortnox_integration' => env(
        'FEATURE_FORTNOX_INTEGRATION',
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
    ),
    'billing' => env(
        'FEATURE_BILLING',
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
    ),
    'analytics' => env(
        'FEATURE_ANALYTICS',
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
    ),
    'notifications_center' => env(
        'FEATURE_NOTIFICATIONS_CENTER',
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
    ),
    'platform_operations' => env(
        'FEATURE_PLATFORM_OPERATIONS',
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
    ),
];
