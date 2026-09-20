<?php

namespace App\Enums\Platform;

enum PlatformIncidentSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
