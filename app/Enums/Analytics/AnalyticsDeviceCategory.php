<?php

namespace App\Enums\Analytics;

enum AnalyticsDeviceCategory: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Tablet = 'tablet';
    case Unknown = 'unknown';
}
