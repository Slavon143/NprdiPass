<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class PassportCurrentDraftBelongsToPassport implements PassportReadinessRule
{
    public function code(): string
    {
        return 'passport.current_draft.belongs_to_passport';
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
        if ($context->passport === null || $context->currentDraft === null) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Failed,
                titleKey: 'readiness.passport.current_draft.belongs_to_passport.title',
                messageKey: 'readiness.passport.current_draft.belongs_to_passport.failed',
                safeContext: [
                    'passport_exists' => $context->passport !== null,
                    'draft_exists' => $context->currentDraft !== null,
                ],
            );
        }

        $passportMatch = (int) $context->currentDraft->getAttribute('passport_id') === (int) $context->passport->getKey();
        $companyMatch = (int) $context->currentDraft->getAttribute('company_id') === (int) $context->company->getKey();
        $passed = $passportMatch && $companyMatch;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.current_draft.belongs_to_passport.title',
            messageKey: $passed ? 'readiness.passport.current_draft.belongs_to_passport.passed' : 'readiness.passport.current_draft.belongs_to_passport.failed',
            safeContext: [
                'passport_match' => $passportMatch,
                'company_match' => $companyMatch,
            ],
        );
    }
}
