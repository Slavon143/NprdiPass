<?php

namespace App\Enums\Analytics;

enum AnalyticsReferrerCategory: string
{
    case Direct = 'direct';
    case Internal = 'internal';
    case Search = 'search';
    case Social = 'social';
    case External = 'external';
    case Unknown = 'unknown';
}
