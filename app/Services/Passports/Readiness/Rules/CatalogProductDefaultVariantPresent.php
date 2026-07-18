<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class CatalogProductDefaultVariantPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'catalog.product.default_variant.present';
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
        $hasVariants = $context->product->variants()->count() > 0;
        $hasDefault = $context->product->defaultVariant !== null;

        $passed = ! $hasVariants || $hasDefault;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.catalog.product.default_variant.present.title',
            messageKey: $passed ? 'readiness.catalog.product.default_variant.present.passed' : 'readiness.catalog.product.default_variant.present.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'catalog_product',
                section: null,
                routeName: 'catalog.products.variants.index',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Product variants',
            ),
            safeContext: [
                'has_variants' => $hasVariants,
                'has_default' => $hasDefault,
            ],
        );
    }
}
