<?php

return [
    'default_language' => env('PASSPORT_DEFAULT_LANGUAGE', 'sv'),
    'schema_version' => '1',
    'max_payload_size' => 1_048_576,

    // Best-effort storage-level immutability for published asset files on the
    // local driver (chmod read-only). Remote backends should enforce
    // immutability at the storage layer (object lock/versioning).
    'asset_readonly' => filter_var(env('PASSPORT_ASSET_READONLY', true), FILTER_VALIDATE_BOOL),

    'public_base_url' => env('PASSPORT_PUBLIC_BASE_URL', env('APP_URL')),

    'demo_password' => env('NORDIPASS_DEMO_PASSWORD', null),

    'qr' => [
        'renderer_version' => '1',

        'foreground' => '#000000',
        'background' => '#ffffff',

        'error_correction' => 'medium',

        'preview_size' => 280,
        'download_size' => 1024,

        'quiet_zone' => 4,

        'print' => [
            'min_recommended_size_mm' => 25,
            'recommended_packaging_size_mm' => 30,
        ],
    ],
];
