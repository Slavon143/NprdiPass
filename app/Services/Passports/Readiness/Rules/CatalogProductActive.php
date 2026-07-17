<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class CatalogProductActive implements PassportReadinessRule
{
    public function code(): string
    {
        return 'catalog.product.active';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Catalog;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $passed = $context->product->status !== ProductStatus::Archived;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.catalog.product.active.title',
            messageKey: $passed ? 'readiness.catalog.product.active.passed' : 'readiness.catalog.product.active.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'catalog_product',
                section: null,
                routeName: 'catalog.products.show',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'View Product',
            ),
            safeContext: [
                'product_status' => $context->product->status->value,
            ],
        );
    }
}
