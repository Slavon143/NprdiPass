<?php

use App\Models\AuditLog;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

return [
    'enabled' => env('ACTIVITYLOG_ENABLED', true),
    'clean_after_days' => (int) env('AUDIT_RETENTION_DAYS', 365),
    'default_log_name' => 'audit',
    'default_auth_driver' => null,
    'include_soft_deleted_subjects' => true,
    'activity_model' => AuditLog::class,
    'default_except_attributes' => [],
    'buffer' => [
        'enabled' => false,
    ],
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
