<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class PassportRevisionValid implements PassportReadinessRule
{
    public function code(): string
    {
        return 'passport.revision.valid';
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
        if ($context->currentDraft === null) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Failed,
                titleKey: 'readiness.passport.revision.valid.title',
                messageKey: 'readiness.passport.revision.valid.failed',
                safeContext: ['draft_exists' => false],
            );
        }

        $passed = $context->currentDraft->draft_revision >= 1;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.revision.valid.title',
            messageKey: $passed ? 'readiness.passport.revision.valid.passed' : 'readiness.passport.revision.valid.failed',
            safeContext: [
                'draft_revision' => $context->currentDraft->draft_revision,
            ],
        );
    }
}
