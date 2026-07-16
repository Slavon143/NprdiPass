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

    'operations' => [
        'stale_draft_days' => (int) env('CATALOG_STALE_DRAFT_DAYS', 90),
        'integrity_batch_size' => (int) env('CATALOG_INTEGRITY_BATCH_SIZE', 500),
        'cleanup_min_age_hours' => (int) env('CATALOG_CLEANUP_MIN_AGE_HOURS', 24),
        'cleanup_max_batch_size' => (int) env('CATALOG_CLEANUP_MAX_BATCH_SIZE', 1000),
        'summary_batch_size' => (int) env('CATALOG_SUMMARY_BATCH_SIZE', 500),
    ],

    'audit' => [
        'per_page_options' => [25, 50, 100],
        'default_per_page' => 50,
        'max_date_range_days' => 366,
        'resource_types' => [
            'category',
            'product',
            'variant',
            'attribute_definition',
            'attribute_option',
            'product_media',
            'variant_media',
        ],
    ],
];
