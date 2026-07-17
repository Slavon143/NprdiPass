<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class CatalogProductExists implements PassportReadinessRule
{
    public function code(): string
    {
        return 'catalog.product.exists';
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
        $passed = $context->product->getAttribute('company_id') === $context->company->getKey();

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.catalog.product.exists.title',
            messageKey: $passed ? 'readiness.catalog.product.exists.passed' : 'readiness.catalog.product.exists.failed',
            safeContext: [
                'product_id' => $context->product->getKey(),
                'product_uuid' => $context->product->uuid,
            ],
        );
    }
}
