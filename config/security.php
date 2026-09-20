<?php

return [
    'hsts_enabled' => filter_var(env('SECURITY_HSTS_ENABLED', false), FILTER_VALIDATE_BOOL),

    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

    'hsts_include_subdomains' => filter_var(env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false), FILTER_VALIDATE_BOOL),

    'hsts_preload' => filter_var(env('SECURITY_HSTS_PRELOAD', false), FILTER_VALIDATE_BOOL),

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'trusted_hosts' => env('TRUSTED_HOSTS', 'localhost,127.0.0.1'),

    /*
     * Content-Security-Policy. Enabled for deployed environments via
     * SECURITY_CSP_ENABLED; keep disabled for local Vite/HMR development.
     * SECURITY_CSP_REPORT_ONLY switches to Content-Security-Policy-Report-Only.
     */
    'csp_enabled' => filter_var(env('SECURITY_CSP_ENABLED', false), FILTER_VALIDATE_BOOL),

    'csp_report_only' => filter_var(env('SECURITY_CSP_REPORT_ONLY', false), FILTER_VALIDATE_BOOL),
];
