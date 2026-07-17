<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class CatalogProductIdentifierPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'catalog.product.identifier.present';
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
        $defaultVariant = $context->product->defaultVariant;
        $hasIdentifier = false;

        if ($defaultVariant !== null) {
            $hasIdentifier = ! empty($defaultVariant->sku)
                || ! empty($defaultVariant->gtin)
                || ! empty($defaultVariant->mpn);
        }

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $hasIdentifier ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.catalog.product.identifier.present.title',
            messageKey: $hasIdentifier ? 'readiness.catalog.product.identifier.present.passed' : 'readiness.catalog.product.identifier.present.failed',
            navigationTarget: $hasIdentifier ? null : new ReadinessNavigationTarget(
                type: 'catalog_product',
                section: null,
                routeName: 'catalog.products.show',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'View Product',
            ),
            safeContext: [
                'has_default_variant' => $defaultVariant !== null,
                'has_sku' => ! empty($defaultVariant->sku),
                'has_gtin' => ! empty($defaultVariant->gtin),
                'has_mpn' => ! empty($defaultVariant->mpn),
            ],
        );
    }
}
