<?php

namespace App\Enums\Passports\Readiness;

enum ReadinessSeverity: string
{
    case Blocker = 'blocker';
    case Warning = 'warning';
    case Recommendation = 'recommendation';
}
