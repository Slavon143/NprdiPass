<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class CatalogProductCategoryPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'catalog.product.category.present';
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
        $hasCategories = $context->product->categories()->count() > 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $hasCategories ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.catalog.product.category.present.title',
            messageKey: $hasCategories ? 'readiness.catalog.product.category.present.passed' : 'readiness.catalog.product.category.present.failed',
            navigationTarget: $hasCategories ? null : new ReadinessNavigationTarget(
                type: 'catalog_product',
                section: null,
                routeName: 'catalog.products.show',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'View Product',
            ),
            safeContext: [
                'has_categories' => $hasCategories,
            ],
        );
    }
}
