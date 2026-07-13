<?php

return [
    'hsts_enabled' => filter_var(env('SECURITY_HSTS_ENABLED', false), FILTER_VALIDATE_BOOL),

    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

    'hsts_include_subdomains' => filter_var(env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false), FILTER_VALIDATE_BOOL),

    'hsts_preload' => filter_var(env('SECURITY_HSTS_PRELOAD', false), FILTER_VALIDATE_BOOL),

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'trusted_hosts' => env('TRUSTED_HOSTS', 'localhost,127.0.0.1'),
];
