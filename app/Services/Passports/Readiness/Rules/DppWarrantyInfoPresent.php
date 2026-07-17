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

class DppWarrantyInfoPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.warranty.information.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Support;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $enabledSections = $context->normalizedPayload['enabled_sections'] ?? [];

        if (! in_array(DppSectionKey::SupportAndContact->value, $enabledSections, true)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.warranty.information.present.title',
                messageKey: 'readiness.dpp.warranty.information.present.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $supportData = $context->normalizedPayload['data']['support_and_contact'] ?? [];

        $warrantySummary = $supportData['warranty_summary'] ?? null;
        $warrantyUrl = $supportData['warranty_url'] ?? null;

        $passed = ! empty($warrantySummary) || ! empty($warrantyUrl);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.warranty.information.present.title',
            messageKey: $passed ? 'readiness.dpp.warranty.information.present.passed' : 'readiness.dpp.warranty.information.present.failed',
            section: DppSectionKey::SupportAndContact,
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::SupportAndContact->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Support',
            ),
            safeContext: [
                'has_warranty_summary' => ! empty($warrantySummary),
                'has_warranty_url' => ! empty($warrantyUrl),
            ],
        );
    }
}
