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

    // Consent happens on Fortnox before the callback; a callback check alone
    // cannot prevent a user accepting an order in the external portal.
    'nonproduction_oauth_enabled' => (bool) env('FORTNOX_NONPRODUCTION_OAUTH_ENABLED', false),
    'nonproduction_tenant_id' => (string) env('FORTNOX_NONPRODUCTION_TENANT_ID', '1854146'),
    // Operator-approved integration client; its portal owner is not the API tenant.
    'nonproduction_client_id' => (string) env('FORTNOX_NONPRODUCTION_CLIENT_ID', ''),

    // Additional scopes require renewed consent for existing connections.
    'scopes' => array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', (string) env('FORTNOX_SCOPES', 'article')),
    )))),

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
    'sync_max_pages_per_run' => (int) env('FORTNOX_SYNC_MAX_PAGES_PER_RUN', 1000),

    // A sync run that stays "running" longer than this is treated as an
    // abandoned run (crashed process) and is resumed from its saved cursor
    // instead of being rejected as a concurrent duplicate.
    'sync_run_stale_minutes' => (int) env('FORTNOX_SYNC_RUN_STALE_MINUTES', 30),

    'reconciliation' => [
        // Safety cap for the exhaustive article fetch. When exceeded, the
        // report is marked incomplete and no mutations are applied.
        'max_articles' => (int) env('FORTNOX_RECONCILIATION_MAX_ARTICLES', 100000),
        'max_pages' => (int) env('FORTNOX_RECONCILIATION_MAX_PAGES', 1000),
        // Default handling for Fortnox articles that disappeared: 'report' only.
        'deletion_policy' => env('FORTNOX_RECONCILIATION_DELETION_POLICY', 'report'),
    ],

    'retry' => [
        'max_attempts' => (int) env('FORTNOX_RETRY_MAX_ATTEMPTS', 3),
        'base_delay_ms' => (int) env('FORTNOX_RETRY_BASE_DELAY_MS', 250),
        'backoff_multiplier' => (int) env('FORTNOX_RETRY_BACKOFF_MULTIPLIER', 2),
    ],
];
