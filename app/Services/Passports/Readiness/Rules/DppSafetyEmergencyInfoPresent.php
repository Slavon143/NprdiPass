<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class DppSafetyEmergencyInfoPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.safety.emergency_information.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Safety;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $defaultLanguage = $context->passport->default_language ?? 'sv';

        $emergencyInstructions = $context->normalizedPayload['data']['safety']['emergency_instructions']
            ?? $context->normalizedPayload['translations'][$defaultLanguage]['safety']['emergency_instructions']
            ?? $context->normalizedPayload['translations']['sv']['safety']['emergency_instructions']
            ?? null;

        $passed = ! empty($emergencyInstructions);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.safety.emergency_information.present.title',
            messageKey: $passed ? 'readiness.dpp.safety.emergency_information.present.passed' : 'readiness.dpp.safety.emergency_information.present.failed',
            section: DppSectionKey::Safety,
            field: 'emergency_instructions',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::Safety->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Safety',
            ),
            safeContext: [
                'emergency_instructions_exists' => ! empty($emergencyInstructions),
            ],
        );
    }
}
