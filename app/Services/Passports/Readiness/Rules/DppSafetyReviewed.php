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

class DppSafetyReviewed implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.safety.reviewed';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Safety;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $defaultLanguage = $context->passport->default_language ?? 'sv';

        $safetyData = $context->normalizedPayload['data']['safety'] ?? [];

        $safetyTranslations = $context->normalizedPayload['translations'][$defaultLanguage]['safety']
            ?? $context->normalizedPayload['translations']['sv']['safety']
            ?? [];

        $allFields = array_merge($safetyData, $safetyTranslations);

        $fields = [
            'warnings' => $allFields['warnings'] ?? null,
            'hazards' => $allFields['hazards'] ?? null,
            'storage_instructions' => $allFields['storage_instructions'] ?? null,
            'emergency_instructions' => $allFields['emergency_instructions'] ?? null,
            'age_restrictions' => $allFields['age_restrictions'] ?? null,
        ];

        $hasContent = false;

        foreach ($fields as $value) {
            if (is_array($value) && count($value) > 0) {
                $hasContent = true;
                break;
            }
            if (is_string($value) && trim($value) !== '') {
                $hasContent = true;
                break;
            }
        }

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $hasContent ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.safety.reviewed.title',
            messageKey: $hasContent ? 'readiness.dpp.safety.reviewed.passed' : 'readiness.dpp.safety.reviewed.failed',
            section: DppSectionKey::Safety,
            navigationTarget: $hasContent ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::Safety->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Safety',
            ),
            safeContext: [
                'fields_checked' => array_keys($fields),
                'has_any_safety_content' => $hasContent,
            ],
        );
    }
}
