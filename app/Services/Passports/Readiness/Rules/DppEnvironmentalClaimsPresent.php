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

class DppEnvironmentalClaimsPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.environmental.claims.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Environmental;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $enabledSections = $context->normalizedPayload['enabled_sections'] ?? [];

        if (! in_array(DppSectionKey::EnvironmentalInformation->value, $enabledSections, true)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.environmental.claims.present.title',
                messageKey: 'readiness.dpp.environmental.claims.present.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $envData = $context->normalizedPayload['data']['environmental_information'] ?? [];

        $environmentalClaims = $envData['environmental_claims'] ?? null;
        $environmentalNotes = $envData['environmental_notes'] ?? null;

        $defaultLang = $context->passport->default_language ?? 'sv';
        $envTranslations = $context->normalizedPayload['translations'][$defaultLang]['environmental_information'] ?? [];

        if ($environmentalClaims === null) {
            $environmentalClaims = $envTranslations['environmental_claims'] ?? null;
        }

        if ($environmentalNotes === null) {
            $environmentalNotes = $envTranslations['environmental_notes'] ?? null;
        }

        $passed = ! empty($environmentalClaims) || ! empty($environmentalNotes);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.environmental.claims.present.title',
            messageKey: $passed ? 'readiness.dpp.environmental.claims.present.passed' : 'readiness.dpp.environmental.claims.present.failed',
            section: DppSectionKey::EnvironmentalInformation,
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::EnvironmentalInformation->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Environmental',
            ),
            safeContext: [
                'has_environmental_claims' => ! empty($environmentalClaims),
                'has_environmental_notes' => ! empty($environmentalNotes),
            ],
        );
    }
}
