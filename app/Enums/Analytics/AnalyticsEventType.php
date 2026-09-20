<?php

namespace App\Enums\Analytics;

enum AnalyticsEventType: string
{
    case QrScan = 'qr_scan';
    case PassportView = 'passport_view';
}
