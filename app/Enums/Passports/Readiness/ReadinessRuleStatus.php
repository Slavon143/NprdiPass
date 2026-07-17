<?php

namespace App\Enums\Passports\Readiness;

enum ReadinessRuleStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';
}
