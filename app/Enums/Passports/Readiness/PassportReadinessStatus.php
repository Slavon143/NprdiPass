<?php

namespace App\Enums\Passports\Readiness;

enum PassportReadinessStatus: string
{
    case NotReady = 'not_ready';
    case ReadyWithWarnings = 'ready_with_warnings';
    case Ready = 'ready';
}
