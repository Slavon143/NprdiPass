<?php

return [
    'raw_retention_days' => (int) env('ANALYTICS_RAW_RETENTION_DAYS', 90),
    'bot_retention_days' => (int) env('ANALYTICS_BOT_RETENTION_DAYS', 30),
    'daily_retention_days' => (int) env('ANALYTICS_DAILY_RETENTION_DAYS', 1095),
    'dedupe_seconds' => (int) env('ANALYTICS_DEDUPE_SECONDS', 60),
];
