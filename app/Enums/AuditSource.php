<?php

namespace App\Enums;

enum AuditSource: string
{
    case Web = 'web';
    case Api = 'api';
    case Console = 'console';
    case Scheduler = 'scheduler';
    case System = 'system';
}
