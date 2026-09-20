<?php

return [
    // Provider used to talk to Fortnox. Local/testing default to the in-process
    // fake provider; deployed environments must configure a real provider.
    'provider' => env(
        'FORTNOX_PROVIDER',
        in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) ? 'fake' : 'http',
    ),

    'client_id' => (string) env('FORTNOX_CLIENT_ID', ''),
    'client_secret' => (string) env('FORTNOX_CLIENT_SECRET', ''),

    'base_url' => rtrim((string) env('FORTNOX_BASE_URL', 'https://api.fortnox.se'), '/'),
    'authorize_url' => rtrim((string) env('FORTNOX_AUTHORIZE_URL', 'https://apps.fortnox.se/oauth-v1/auth'), '/'),
    'token_url' => rtrim((string) env('FORTNOX_TOKEN_URL', 'https://apps.fortnox.se/oauth-v1/token'), '/'),
    'ca_bundle' => (string) env('FORTNOX_CA_BUNDLE', ''),

    'redirect_path' => '/settings/integrations/fortnox/callback',

    'scopes' => ['article'],

    // Hosts the real HTTP provider is allowed to contact. SSRF protection:
    // any other host (including IP literals and private ranges) is rejected.
    // Fortnox uses api.fortnox.se for API calls and apps.fortnox.se for OAuth.
    'allowed_api_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FORTNOX_ALLOWED_API_HOSTS', 'api.fortnox.se,apps.fortnox.se')),
    ))),

    'oauth_state_ttl_minutes' => (int) env('FORTNOX_OAUTH_STATE_TTL_MINUTES', 10),

    // Refresh the access token this many seconds before it expires.
    'token_refresh_margin_seconds' => (int) env('FORTNOX_TOKEN_REFRESH_MARGIN_SECONDS', 300),

    'sync_page_size' => (int) env('FORTNOX_SYNC_PAGE_SIZE', 100),
    'sync_chunk_size' => (int) env('FORTNOX_SYNC_CHUNK_SIZE', 100),
    'sync_max_articles_per_run' => (int) env('FORTNOX_SYNC_MAX_ARTICLES_PER_RUN', 5000),

    'retry' => [
        'max_attempts' => (int) env('FORTNOX_RETRY_MAX_ATTEMPTS', 3),
        'base_delay_ms' => (int) env('FORTNOX_RETRY_BASE_DELAY_MS', 250),
        'backoff_multiplier' => (int) env('FORTNOX_RETRY_BACKOFF_MULTIPLIER', 2),
    ],
];
