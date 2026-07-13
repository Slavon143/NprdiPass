<?php

return [
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),
    'ip_storage' => (bool) env('AUDIT_STORE_IP', true),
    'user_agent_max_length' => (int) env('AUDIT_USER_AGENT_MAX_LENGTH', 500),
    'failed_login_per_minute' => 20,
];
