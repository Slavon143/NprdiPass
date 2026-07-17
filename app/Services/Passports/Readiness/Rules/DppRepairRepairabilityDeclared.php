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

class DppRepairRepairabilityDeclared implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.repair.repairability_declared';
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

        if (! in_array(DppSectionKey::RepairAndSpareParts->value, $enabledSections, true)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.repair.repairability_declared.title',
                messageKey: 'readiness.dpp.repair.repairability_declared.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $repairable = $context->normalizedPayload['data']['repair_and_spare_parts']['repairable']
            ?? null;

        $passed = $repairable !== null;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.repair.repairability_declared.title',
            messageKey: $passed ? 'readiness.dpp.repair.repairability_declared.passed' : 'readiness.dpp.repair.repairability_declared.failed',
            section: DppSectionKey::RepairAndSpareParts,
            field: 'repairable',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::RepairAndSpareParts->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Repair',
            ),
            safeContext: [
                'repairable' => $repairable,
            ],
        );
    }
}
