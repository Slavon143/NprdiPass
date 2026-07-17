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

class DppManufacturerNamePresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.manufacturer.name.present';
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
        $defaultLanguage = $context->passport->default_language ?? 'sv';

        $dppManufacturer = $context->normalizedPayload['data']['manufacturer_and_operator']['manufacturer_display_name']
            ?? $context->normalizedPayload['translations'][$defaultLanguage]['manufacturer_and_operator']['manufacturer_display_name']
            ?? $context->normalizedPayload['translations']['sv']['manufacturer_and_operator']['manufacturer_display_name']
            ?? null;

        $catalogManufacturer = $context->product->manufacturer;

        $passed = ! empty($dppManufacturer) || ! empty($catalogManufacturer);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.manufacturer.name.present.title',
            messageKey: $passed ? 'readiness.dpp.manufacturer.name.present.passed' : 'readiness.dpp.manufacturer.name.present.failed',
            section: DppSectionKey::ManufacturerAndOperator,
            field: 'manufacturer_display_name',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::ManufacturerAndOperator->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Manufacturer',
            ),
            safeContext: [
                'dpp_manufacturer_exists' => ! empty($dppManufacturer),
                'catalog_manufacturer_exists' => ! empty($catalogManufacturer),
            ],
        );
    }
}
