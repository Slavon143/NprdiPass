<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use Illuminate\Support\Facades\Storage;

class MediaPrimaryAvailable implements PassportReadinessRule
{
    public function code(): string
    {
        return 'media.primary.available';
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
                titleKey: 'readiness.media.primary.available.title',
                messageKey: 'readiness.media.primary.available.failed',
                safeContext: ['has_media' => false],
            );
        }

        $storagePath = $primaryMedia->getAttribute('storage_path');
        $exists = ! empty($storagePath) && Storage::disk('catalog_media')->exists($storagePath);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $exists ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.media.primary.available.title',
            messageKey: $exists ? 'readiness.media.primary.available.passed' : 'readiness.media.primary.available.failed',
            navigationTarget: $exists ? null : new ReadinessNavigationTarget(
                type: 'product_media',
                section: null,
                routeName: 'catalog.products.media.index',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Product images',
            ),
            safeContext: [
                'storage_path' => $storagePath,
                'file_exists' => $exists,
            ],
        );
    }
}
