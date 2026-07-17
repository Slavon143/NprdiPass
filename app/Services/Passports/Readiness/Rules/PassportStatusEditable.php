<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class PassportStatusEditable implements PassportReadinessRule
{
    public function code(): string
    {
        return 'passport.status.editable';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Passport;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        if ($context->passport === null) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Failed,
                titleKey: 'readiness.passport.status.editable.title',
                messageKey: 'readiness.passport.status.editable.failed',
                safeContext: ['passport_exists' => false],
            );
        }

        $passed = $context->passport->status === ProductPassportStatus::Draft
            || $context->passport->status === ProductPassportStatus::Unpublished;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.status.editable.title',
            messageKey: $passed ? 'readiness.passport.status.editable.passed' : 'readiness.passport.status.editable.failed',
            safeContext: [
                'passport_status' => $context->passport->status->value,
            ],
        );
    }
}
