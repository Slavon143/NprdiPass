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

class CatalogProductManufacturerPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'catalog.product.manufacturer.present';
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
        $catalogManufacturer = $context->product->manufacturer;
        $dppManufacturer = $context->normalizedPayload['data']['manufacturer_and_operator']['manufacturer_display_name']
            ?? null;

        $passed = ! empty($catalogManufacturer) || ! empty($dppManufacturer);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.catalog.product.manufacturer.present.title',
            messageKey: $passed ? 'readiness.catalog.product.manufacturer.present.passed' : 'readiness.catalog.product.manufacturer.present.failed',
            section: DppSectionKey::ManufacturerAndOperator,
            field: 'manufacturer_display_name',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'catalog_product',
                section: null,
                routeName: 'catalog.products.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Product manufacturer',
            ),
            safeContext: [
                'catalog_manufacturer_exists' => ! empty($catalogManufacturer),
                'dpp_manufacturer_exists' => ! empty($dppManufacturer),
            ],
        );
    }
}
