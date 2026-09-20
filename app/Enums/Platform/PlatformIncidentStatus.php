<?php

namespace App\Enums\Platform;

enum PlatformIncidentStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
