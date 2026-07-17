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

class DppMaterialsPercentageComplete implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.materials.percentage_complete';
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

        if (! in_array(DppSectionKey::MaterialsAndComposition->value, $enabledSections, true)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.materials.percentage_complete.title',
                messageKey: 'readiness.dpp.materials.percentage_complete.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $materials = $context->normalizedPayload['data']['materials_and_composition']['materials']
            ?? [];

        if (! is_array($materials) || count($materials) === 0) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Failed,
                titleKey: 'readiness.dpp.materials.percentage_complete.title',
                messageKey: 'readiness.dpp.materials.percentage_complete.failed',
                section: DppSectionKey::MaterialsAndComposition,
                safeContext: ['materials_empty' => true],
            );
        }

        $allHavePercentage = true;
        $missingCount = 0;

        foreach ($materials as $material) {
            if (! isset($material['percentage']) || ! is_numeric($material['percentage'])) {
                $allHavePercentage = false;
                $missingCount++;
            }
        }

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $allHavePercentage ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.materials.percentage_complete.title',
            messageKey: $allHavePercentage ? 'readiness.dpp.materials.percentage_complete.passed' : 'readiness.dpp.materials.percentage_complete.failed',
            section: DppSectionKey::MaterialsAndComposition,
            field: 'materials',
            navigationTarget: $allHavePercentage ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::MaterialsAndComposition->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Materials',
            ),
            safeContext: [
                'materials_count' => count($materials),
                'missing_percentage_count' => $missingCount,
            ],
        );
    }
}
