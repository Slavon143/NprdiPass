<?php

namespace App\Contracts\Passports;

use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessSeverity;

interface PassportReadinessRule
{
    public function code(): string;

    public function group(): ReadinessRuleGroup;

    public function severity(): ReadinessSeverity;

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult;
}
