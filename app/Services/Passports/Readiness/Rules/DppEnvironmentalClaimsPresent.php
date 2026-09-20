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

        $envData = $context->sectionData(DppSectionKey::EnvironmentalInformation->value);
        $environmentalClaims = $envData['environmental_claims'] ?? null;
        $environmentalClaimRecords = $envData['environmental_claim_records'] ?? null;
        $environmentalNotes = $envData['environmental_notes'] ?? null;
        $passed = ! empty($environmentalClaims)
            || ! empty($environmentalNotes)
            || (is_array($environmentalClaimRecords) && $environmentalClaimRecords !== []);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.environmental.claims.present.title',
            messageKey: $passed ? 'readiness.dpp.environmental.claims.present.passed' : 'readiness.dpp.environmental.claims.present.failed',
            section: DppSectionKey::EnvironmentalInformation,
            field: 'environmental_claim_records',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::EnvironmentalInformation->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Environmental',
            ),
            safeContext: [
                'has_environmental_claims' => ! empty($environmentalClaims),
                'has_environmental_notes' => ! empty($environmentalNotes),
                'has_environmental_claim_records' => is_array($environmentalClaimRecords) && $environmentalClaimRecords !== [],
            ],
        );
    }
}
