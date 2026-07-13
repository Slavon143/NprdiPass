<?php

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env(
        'API_ALLOWED_ORIGINS',
        'http://localhost:3000,http://localhost:5173,http://127.0.0.1:5173',
    )),
)));

return [
    'default_token_expiration_days' => (int) env('API_DEFAULT_TOKEN_EXPIRATION_DAYS', 90),
    'max_token_expiration_days' => (int) env('API_MAX_TOKEN_EXPIRATION_DAYS', 365),
    'allow_non_expiring_tokens' => (bool) env('API_ALLOW_NON_EXPIRING_TOKENS', false),
    'token_retention_days' => (int) env('API_TOKEN_RETENTION_DAYS', 30),
    'prune_inactive_user_tokens' => (bool) env('API_PRUNE_INACTIVE_USER_TOKENS', true),
    'allowed_origins' => $origins,
];
