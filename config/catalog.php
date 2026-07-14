<?php

return [
    'media' => [
        'disk' => env('CATALOG_MEDIA_DISK', 'catalog_media'),
        'max_file_size_kb' => 10 * 1024,
        'max_width' => 12000,
        'max_height' => 12000,
        'max_pixels' => 40_000_000,
        'max_per_product' => 50,
        'max_per_variant' => 10,
        'alt_text_max' => 500,
        'caption_max' => 500,
        'original_filename_max' => 255,
        'cleanup_older_than_hours' => 24,
        'cleanup_limit' => 500,
        'test_root_marker' => 'framework/testing/disks',
        'mime_extensions' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
    ],
];
