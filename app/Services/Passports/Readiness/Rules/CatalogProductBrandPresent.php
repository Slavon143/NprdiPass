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

class CatalogProductBrandPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'catalog.product.brand.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Catalog;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $catalogBrand = $context->product->brand;
        $dppBrand = $context->normalizedPayload['data']['manufacturer_and_operator']['manufacturer_display_name']
            ?? $context->normalizedPayload['data']['manufacturer_and_operator']['manufacturer_display_name']
            ?? null;

        $passed = ! empty($catalogBrand) || ! empty($dppBrand);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.catalog.product.brand.present.title',
            messageKey: $passed ? 'readiness.catalog.product.brand.present.passed' : 'readiness.catalog.product.brand.present.failed',
            section: DppSectionKey::ManufacturerAndOperator,
            field: 'manufacturer_display_name',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::ManufacturerAndOperator->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Manufacturer',
            ),
            safeContext: [
                'catalog_brand_exists' => ! empty($catalogBrand),
                'dpp_brand_exists' => ! empty($dppBrand),
            ],
        );
    }
}
