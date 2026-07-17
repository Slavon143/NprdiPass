<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class CatalogProductAttributesPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'catalog.product.attributes.present';
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
        $hasAttributes = $context->product->attributeValues()->count() > 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $hasAttributes ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.catalog.product.attributes.present.title',
            messageKey: $hasAttributes ? 'readiness.catalog.product.attributes.present.passed' : 'readiness.catalog.product.attributes.present.failed',
            navigationTarget: $hasAttributes ? null : new ReadinessNavigationTarget(
                type: 'catalog_product',
                section: null,
                routeName: 'catalog.products.show',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'View Product',
            ),
            safeContext: [
                'has_attributes' => $hasAttributes,
            ],
        );
    }
}
