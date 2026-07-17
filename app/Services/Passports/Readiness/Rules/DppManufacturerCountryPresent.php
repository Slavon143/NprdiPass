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

class DppManufacturerCountryPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.manufacturer.country.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Manufacturer;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $manufacturerCountry = $context->normalizedPayload['data']['manufacturer_and_operator']['manufacturer_country']
            ?? null;

        $passed = ! empty($manufacturerCountry);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.manufacturer.country.present.title',
            messageKey: $passed ? 'readiness.dpp.manufacturer.country.present.passed' : 'readiness.dpp.manufacturer.country.present.failed',
            section: DppSectionKey::ManufacturerAndOperator,
            field: 'manufacturer_country',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::ManufacturerAndOperator->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Manufacturer',
            ),
            safeContext: [
                'manufacturer_country_exists' => ! empty($manufacturerCountry),
            ],
        );
    }
}
