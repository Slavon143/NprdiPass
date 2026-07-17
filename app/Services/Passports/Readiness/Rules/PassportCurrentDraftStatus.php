<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class PassportCurrentDraftStatus implements PassportReadinessRule
{
    public function code(): string
    {
        return 'passport.current_draft.status';
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
                titleKey: 'readiness.passport.current_draft.status.title',
                messageKey: 'readiness.passport.current_draft.status.failed',
                safeContext: ['draft_exists' => false],
            );
        }

        $passed = $context->currentDraft->status === ProductPassportVersionStatus::Draft;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.current_draft.status.title',
            messageKey: $passed ? 'readiness.passport.current_draft.status.passed' : 'readiness.passport.current_draft.status.failed',
            safeContext: [
                'draft_status' => $context->currentDraft->status->value,
            ],
        );
    }
}
