<?php

return [
    'default_language' => env('PASSPORT_DEFAULT_LANGUAGE', 'sv'),
    'schema_version' => '1',
    'max_payload_size' => 1_048_576,

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
