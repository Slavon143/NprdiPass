<?php

return [
    'enabled' => filter_var(env('BACKUP_ENABLED', true), FILTER_VALIDATE_BOOL),

    'disk' => env('BACKUP_DISK', 'local'),

    'path' => env('BACKUP_PATH', 'nordipass/backups'),

    'temporary_path' => env('BACKUP_TEMPORARY_PATH', sys_get_temp_dir().DIRECTORY_SEPARATOR.'nordipass-backups'),

    'database' => [
        'enabled' => filter_var(env('BACKUP_DATABASE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'binary' => env('BACKUP_DATABASE_BINARY', ''),
        'restore_binary' => env('RESTORE_DATABASE_BINARY', ''),
    ],

    'files' => [
        'enabled' => filter_var(env('BACKUP_FILES_ENABLED', true), FILTER_VALIDATE_BOOL),
        'disks' => [
            'production_imports',
            'product_documents',
            'catalog_media',
            'passport_assets',
        ],
    ],

    'verify_after_create' => filter_var(env('BACKUP_VERIFY_AFTER_CREATE', true), FILTER_VALIDATE_BOOL),

    'retention' => [
        'daily' => (int) env('BACKUP_RETENTION_DAILY', 7),
        'weekly' => (int) env('BACKUP_RETENTION_WEEKLY', 4),
        'monthly' => (int) env('BACKUP_RETENTION_MONTHLY', 3),
    ],

    'lock_minutes' => (int) env('BACKUP_LOCK_MINUTES', 180),

    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
];
