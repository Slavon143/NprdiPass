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

class DppManufacturerContactPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.manufacturer.contact.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Manufacturer;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $data = $context->normalizedPayload['data']['manufacturer_and_operator'] ?? [];

        $manufacturerEmail = $data['manufacturer_email'] ?? null;
        $manufacturerWebsite = $data['manufacturer_website'] ?? null;
        $operatorEmail = $data['responsible_operator_email'] ?? null;
        $operatorWebsite = $data['responsible_operator_website'] ?? null;

        $passed = ! empty($manufacturerEmail)
            || ! empty($manufacturerWebsite)
            || ! empty($operatorEmail)
            || ! empty($operatorWebsite);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.manufacturer.contact.present.title',
            messageKey: $passed ? 'readiness.dpp.manufacturer.contact.present.passed' : 'readiness.dpp.manufacturer.contact.present.failed',
            section: DppSectionKey::ManufacturerAndOperator,
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::ManufacturerAndOperator->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Manufacturer',
            ),
            safeContext: [
                'has_manufacturer_email' => ! empty($manufacturerEmail),
                'has_manufacturer_website' => ! empty($manufacturerWebsite),
                'has_operator_email' => ! empty($operatorEmail),
                'has_operator_website' => ! empty($operatorWebsite),
            ],
        );
    }
}
