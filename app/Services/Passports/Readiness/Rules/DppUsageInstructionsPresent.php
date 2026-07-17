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

class DppUsageInstructionsPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.usage.instructions.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Technical;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $enabledSections = $context->normalizedPayload['enabled_sections'] ?? [];

        if (! in_array(DppSectionKey::UsageAndCare->value, $enabledSections, true)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.usage.instructions.present.title',
                messageKey: 'readiness.dpp.usage.instructions.present.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $usageInstructions = $context->normalizedPayload['data']['usage_and_care']['usage_instructions']
            ?? null;

        $passed = ! empty($usageInstructions);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.usage.instructions.present.title',
            messageKey: $passed ? 'readiness.dpp.usage.instructions.present.passed' : 'readiness.dpp.usage.instructions.present.failed',
            section: DppSectionKey::UsageAndCare,
            field: 'usage_instructions',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::UsageAndCare->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Usage & Care',
            ),
            safeContext: [
                'usage_instructions_exists' => ! empty($usageInstructions),
            ],
        );
    }
}
