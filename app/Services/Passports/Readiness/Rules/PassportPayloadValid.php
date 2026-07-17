<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Services\Passports\DppPayloadValidator;
use Illuminate\Validation\ValidationException;

class PassportPayloadValid implements PassportReadinessRule
{
    private DppPayloadValidator $validator;

    public function __construct(DppPayloadValidator $validator)
    {
        $this->validator = $validator;
    }

    public function code(): string
    {
        return 'passport.payload.valid';
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
        if (empty($context->normalizedPayload)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Failed,
                titleKey: 'readiness.passport.payload.valid.title',
                messageKey: 'readiness.passport.payload.valid.failed',
                safeContext: ['payload_empty' => true],
            );
        }

        try {
            $this->validator->validateFullPayload(
                $context->normalizedPayload,
                $context->company,
                $context->passport,
            );

            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Passed,
                titleKey: 'readiness.passport.payload.valid.title',
                messageKey: 'readiness.passport.payload.valid.passed',
                safeContext: [],
            );
        } catch (ValidationException $e) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Failed,
                titleKey: 'readiness.passport.payload.valid.title',
                messageKey: 'readiness.passport.payload.valid.failed',
                safeContext: [
                    'validation_errors' => $e->errors(),
                ],
            );
        }
    }
}
