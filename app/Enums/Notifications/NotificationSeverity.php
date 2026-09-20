<?php

namespace App\Enums\Notifications;

enum NotificationSeverity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Critical = 'critical';
}
