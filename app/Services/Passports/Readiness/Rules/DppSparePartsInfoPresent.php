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

class DppSparePartsInfoPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.spare_parts.information.present';
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
                titleKey: 'readiness.dpp.spare_parts.information.present.title',
                messageKey: 'readiness.dpp.spare_parts.information.present.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $sparePartsAvailable = $context->normalizedPayload['data']['repair_and_spare_parts']['spare_parts_available']
            ?? false;

        if ($sparePartsAvailable !== true) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.spare_parts.information.present.title',
                messageKey: 'readiness.dpp.spare_parts.information.present.not_applicable',
                safeContext: ['spare_parts_available' => $sparePartsAvailable],
            );
        }

        $sparePartsUrl = $context->normalizedPayload['data']['repair_and_spare_parts']['spare_parts_url']
            ?? null;
        $sparePartsNotes = $context->normalizedPayload['data']['repair_and_spare_parts']['spare_parts_notes']
            ?? null;

        $passed = ! empty($sparePartsUrl) || ! empty($sparePartsNotes);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.spare_parts.information.present.title',
            messageKey: $passed ? 'readiness.dpp.spare_parts.information.present.passed' : 'readiness.dpp.spare_parts.information.present.failed',
            section: DppSectionKey::RepairAndSpareParts,
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::RepairAndSpareParts->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Repair',
            ),
            safeContext: [
                'has_spare_parts_url' => ! empty($sparePartsUrl),
                'has_spare_parts_notes' => ! empty($sparePartsNotes),
            ],
        );
    }
}
