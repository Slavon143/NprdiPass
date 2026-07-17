<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class MediaPrimaryPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'media.primary.present';
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
        $firstMedia = null;

        if ($primaryMedia === null) {
            $firstMedia = $context->product->productMedia()->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->first();
        }

        $passed = $primaryMedia !== null || $firstMedia !== null;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.media.primary.present.title',
            messageKey: $passed ? 'readiness.media.primary.present.passed' : 'readiness.media.primary.present.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'product_media',
                section: null,
                routeName: 'catalog.products.show',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Manage Media',
            ),
            safeContext: [
                'has_primary_media' => $primaryMedia !== null,
                'has_fallback_media' => $firstMedia !== null,
            ],
        );
    }
}
