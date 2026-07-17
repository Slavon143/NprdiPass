<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class PassportPayloadSize implements PassportReadinessRule
{
    private const MAX_SIZE_BYTES = 1_048_576;

    public function code(): string
    {
        return 'passport.payload.size';
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
        $json = json_encode(
            $context->normalizedPayload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $size = $json !== false ? strlen($json) : 0;
        $passed = $json !== false && $size <= self::MAX_SIZE_BYTES;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.payload.size.title',
            messageKey: $passed ? 'readiness.passport.payload.size.passed' : 'readiness.passport.payload.size.failed',
            safeContext: [
                'payload_size_bytes' => $size,
                'max_size_bytes' => self::MAX_SIZE_BYTES,
            ],
        );
    }
}
