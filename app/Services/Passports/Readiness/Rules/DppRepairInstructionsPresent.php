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

class DppRepairInstructionsPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.repair.instructions.present';
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
                titleKey: 'readiness.dpp.repair.instructions.present.title',
                messageKey: 'readiness.dpp.repair.instructions.present.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $repairable = $context->normalizedPayload['data']['repair_and_spare_parts']['repairable']
            ?? false;

        if ($repairable !== true) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.repair.instructions.present.title',
                messageKey: 'readiness.dpp.repair.instructions.present.not_applicable',
                safeContext: ['repairable' => $repairable],
            );
        }

        $repairInstructions = $context->normalizedPayload['data']['repair_and_spare_parts']['repair_instructions']
            ?? null;

        $passed = ! empty($repairInstructions);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.repair.instructions.present.title',
            messageKey: $passed ? 'readiness.dpp.repair.instructions.present.passed' : 'readiness.dpp.repair.instructions.present.failed',
            section: DppSectionKey::RepairAndSpareParts,
            field: 'repair_instructions',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::RepairAndSpareParts->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Repair',
            ),
            safeContext: [
                'repair_instructions_exists' => ! empty($repairInstructions),
            ],
        );
    }
}
