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

class DppEnvironmentalMetricsPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.environmental.metrics.present';
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
                titleKey: 'readiness.dpp.environmental.metrics.present.title',
                messageKey: 'readiness.dpp.environmental.metrics.present.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $envData = $context->normalizedPayload['data']['environmental_information'] ?? [];

        $carbonFootprint = $envData['carbon_footprint_kg_co2e'] ?? null;
        $recycledContentPercentage = $envData['recycled_content_percentage'] ?? null;
        $expectedLifetimeYears = $envData['expected_lifetime_years'] ?? null;
        $energyConsumptionKwh = $envData['energy_consumption_kwh'] ?? null;

        $allEmpty = empty($carbonFootprint)
            && empty($recycledContentPercentage)
            && empty($expectedLifetimeYears)
            && empty($energyConsumptionKwh);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $allEmpty ? ReadinessRuleStatus::Failed : ReadinessRuleStatus::Passed,
            titleKey: 'readiness.dpp.environmental.metrics.present.title',
            messageKey: $allEmpty ? 'readiness.dpp.environmental.metrics.present.failed' : 'readiness.dpp.environmental.metrics.present.passed',
            section: DppSectionKey::EnvironmentalInformation,
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::EnvironmentalInformation->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Environmental',
            ),
            safeContext: [
                'has_carbon_footprint' => ! empty($carbonFootprint) && is_numeric($carbonFootprint),
                'has_recycled_content' => ! empty($recycledContentPercentage) && is_numeric($recycledContentPercentage),
                'has_expected_lifetime' => ! empty($expectedLifetimeYears) && is_numeric($expectedLifetimeYears),
                'has_energy_consumption' => ! empty($energyConsumptionKwh) && is_numeric($energyConsumptionKwh),
            ],
        );
    }
}
