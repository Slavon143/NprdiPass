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

class DppRecyclingInstructionsPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.recycling.instructions.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Recycling;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $defaultLanguage = $context->passport->default_language ?? 'sv';

        $recyclingData = $context->normalizedPayload['data']['recycling_and_disposal'] ?? [];

        $recyclingTranslations = $context->normalizedPayload['translations'][$defaultLanguage]['recycling_and_disposal']
            ?? $context->normalizedPayload['translations']['sv']['recycling_and_disposal']
            ?? [];

        $allFields = array_merge($recyclingData, $recyclingTranslations);

        $recyclingInstructions = $allFields['recycling_instructions'] ?? null;
        $disposalInstructions = $allFields['disposal_instructions'] ?? null;

        $passed = ! empty($recyclingInstructions) || ! empty($disposalInstructions);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.recycling.instructions.present.title',
            messageKey: $passed ? 'readiness.dpp.recycling.instructions.present.passed' : 'readiness.dpp.recycling.instructions.present.failed',
            section: DppSectionKey::RecyclingAndDisposal,
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::RecyclingAndDisposal->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Recycling',
            ),
            safeContext: [
                'has_recycling_instructions' => ! empty($recyclingInstructions),
                'has_disposal_instructions' => ! empty($disposalInstructions),
            ],
        );
    }
}
