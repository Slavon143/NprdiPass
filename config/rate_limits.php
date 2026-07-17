<?php

return [
    'api' => [
        'per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 120),
    ],

    'api_public' => [
        'per_minute' => (int) env('RATE_LIMIT_API_PUBLIC_PER_MINUTE', 60),
    ],

    'auth' => [
        'per_minute' => (int) env('RATE_LIMIT_AUTH_PER_MINUTE', 5),
    ],

    'invitations' => [
        'manage_per_minute' => (int) env('RATE_LIMIT_INVITATIONS_MANAGE_PER_MINUTE', 10),
        'verify_per_minute' => (int) env('RATE_LIMIT_INVITATIONS_VERIFY_PER_MINUTE', 20),
        'accept_per_minute' => (int) env('RATE_LIMIT_INVITATIONS_ACCEPT_PER_MINUTE', 10),
    ],

    'token_management' => [
        'create_per_minute' => (int) env('RATE_LIMIT_TOKEN_CREATE_PER_MINUTE', 10),
        'revoke_per_minute' => (int) env('RATE_LIMIT_TOKEN_REVOKE_PER_MINUTE', 30),
    ],

    'catalog_api' => [
        'read_per_minute' => (int) env('RATE_LIMIT_CATALOG_API_READ_PER_MINUTE', 120),
        'write_per_minute' => (int) env('RATE_LIMIT_CATALOG_API_WRITE_PER_MINUTE', 60),
        'media_per_minute' => (int) env('RATE_LIMIT_CATALOG_API_MEDIA_PER_MINUTE', 20),
        'lifecycle_per_minute' => (int) env('RATE_LIMIT_CATALOG_API_LIFECYCLE_PER_MINUTE', 30),
    ],

    'documents_api' => [
        'read_per_minute' => (int) env('RATE_LIMIT_DOCUMENTS_API_READ_PER_MINUTE', 120),
        'write_per_minute' => (int) env('RATE_LIMIT_DOCUMENTS_API_WRITE_PER_MINUTE', 60),
        'media_per_minute' => (int) env('RATE_LIMIT_DOCUMENTS_API_MEDIA_PER_MINUTE', 20),
    ],

    'passports_api' => [
        'read_per_minute' => (int) env('RATE_LIMIT_PASSPORTS_API_READ_PER_MINUTE', 120),
        'write_per_minute' => (int) env('RATE_LIMIT_PASSPORTS_API_WRITE_PER_MINUTE', 60),
    ],
];
