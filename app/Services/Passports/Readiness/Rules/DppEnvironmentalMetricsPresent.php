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
        $environmentalMetrics = $envData['environmental_metrics'] ?? null;

        $hasMetric = is_numeric($carbonFootprint)
            || is_numeric($recycledContentPercentage)
            || is_numeric($expectedLifetimeYears)
            || is_numeric($energyConsumptionKwh)
            || (is_array($environmentalMetrics) && $environmentalMetrics !== []);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $hasMetric ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.environmental.metrics.present.title',
            messageKey: $hasMetric ? 'readiness.dpp.environmental.metrics.present.passed' : 'readiness.dpp.environmental.metrics.present.failed',
            section: DppSectionKey::EnvironmentalInformation,
            field: 'environmental_metrics',
            navigationTarget: $hasMetric ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::EnvironmentalInformation->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Environmental',
            ),
            safeContext: [
                'has_carbon_footprint' => is_numeric($carbonFootprint),
                'has_recycled_content' => is_numeric($recycledContentPercentage),
                'has_expected_lifetime' => is_numeric($expectedLifetimeYears),
                'has_energy_consumption' => is_numeric($energyConsumptionKwh),
                'has_structured_metrics' => is_array($environmentalMetrics) && $environmentalMetrics !== [],
            ],
        );
    }
}
