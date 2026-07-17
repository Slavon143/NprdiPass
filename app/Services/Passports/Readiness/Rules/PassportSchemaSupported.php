<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class PassportSchemaSupported implements PassportReadinessRule
{
    public function code(): string
    {
        return 'passport.schema.supported';
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
                titleKey: 'readiness.passport.schema.supported.title',
                messageKey: 'readiness.passport.schema.supported.failed',
                safeContext: ['draft_exists' => false],
            );
        }

        $passed = $context->currentDraft->schema_version === '1';

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.schema.supported.title',
            messageKey: $passed ? 'readiness.passport.schema.supported.passed' : 'readiness.passport.schema.supported.failed',
            safeContext: [
                'schema_version' => $context->currentDraft->schema_version,
            ],
        );
    }
}
