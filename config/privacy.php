<?php

return [
    /*
     * Soft-deleted (erased) user rows are hard-deleted after this many days.
     * Erasure anonymizes personal data immediately; the row is kept for
     * referential integrity and removed by `privacy:purge` after retention.
     */
    'user_erasure_retention_days' => (int) env('PRIVACY_USER_ERASURE_RETENTION_DAYS', 30),

    /*
     * Outbound webhook delivery history is business telemetry, not personal
     * data, but is still retained only for a bounded window.
     */
    'webhook_delivery_retention_days' => (int) env('PRIVACY_WEBHOOK_DELIVERY_RETENTION_DAYS', 90),

    /*
     * Maximum number of tenant audit rows included in a personal data export.
     */
    'export_audit_max_rows' => (int) env('PRIVACY_EXPORT_AUDIT_MAX_ROWS', 1000),
];
