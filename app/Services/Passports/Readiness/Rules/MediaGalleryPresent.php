<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class MediaGalleryPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'media.gallery.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Media;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $mediaCount = $context->product->media()->count();
        $passed = $mediaCount > 1;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.media.gallery.present.title',
            messageKey: $passed ? 'readiness.media.gallery.present.passed' : 'readiness.media.gallery.present.failed',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'product_media',
                section: null,
                routeName: 'catalog.products.show',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Manage Media',
            ),
            safeContext: [
                'media_count' => $mediaCount,
            ],
        );
    }
}
