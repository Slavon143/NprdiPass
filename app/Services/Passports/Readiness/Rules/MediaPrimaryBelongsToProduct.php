<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class MediaPrimaryBelongsToProduct implements PassportReadinessRule
{
    public function code(): string
    {
        return 'media.primary.belongs_to_product';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Media;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $primaryMedia = $context->product->primaryMedia;

        if ($primaryMedia === null) {
            $primaryMedia = $context->product->productMedia()->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->first();
        }

        if ($primaryMedia === null) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Failed,
                titleKey: 'readiness.media.primary.belongs_to_product.title',
                messageKey: 'readiness.media.primary.belongs_to_product.failed',
                safeContext: ['has_media' => false],
            );
        }

        $belongsToProduct = (int) $primaryMedia->getAttribute('product_id') === (int) $context->product->getKey();
        $belongsToCompany = (int) $primaryMedia->getAttribute('company_id') === (int) $context->company->getKey();
        $passed = $belongsToProduct && $belongsToCompany;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.media.primary.belongs_to_product.title',
            messageKey: $passed ? 'readiness.media.primary.belongs_to_product.passed' : 'readiness.media.primary.belongs_to_product.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'product_media',
                section: null,
                routeName: 'catalog.products.show',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Manage Media',
            ),
            safeContext: [
                'belongs_to_product' => $belongsToProduct,
                'belongs_to_company' => $belongsToCompany,
            ],
        );
    }
}
