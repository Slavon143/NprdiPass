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

class DppMaterialsPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.materials.present';
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
                titleKey: 'readiness.dpp.materials.present.title',
                messageKey: 'readiness.dpp.materials.present.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $materials = $context->normalizedPayload['data']['materials_and_composition']['materials']
            ?? [];

        $passed = is_array($materials) && count($materials) > 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.materials.present.title',
            messageKey: $passed ? 'readiness.dpp.materials.present.passed' : 'readiness.dpp.materials.present.failed',
            section: DppSectionKey::MaterialsAndComposition,
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::MaterialsAndComposition->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Materials',
            ),
            safeContext: [
                'materials_count' => is_array($materials) ? count($materials) : 0,
            ],
        );
    }
}
