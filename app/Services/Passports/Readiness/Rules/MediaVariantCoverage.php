<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class MediaVariantCoverage implements PassportReadinessRule
{
    public function code(): string
    {
        return 'media.variant_coverage';
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
        $variants = $context->product->variants ?? [];

        if ($variants->count() === 0) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Passed,
                titleKey: 'readiness.media.variant_coverage.title',
                messageKey: 'readiness.media.variant_coverage.passed',
                safeContext: ['has_variants' => false],
            );
        }

        $variantsWithoutMedia = [];
        $totalVariants = 0;

        foreach ($variants as $variant) {
            $totalVariants++;
            if ($variant->media()->count() === 0) {
                $variantsWithoutMedia[] = $variant->uuid;
            }
        }

        $coverageMissing = count($variantsWithoutMedia) > 0;
        $passed = ! $coverageMissing;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.media.variant_coverage.title',
            messageKey: $passed ? 'readiness.media.variant_coverage.passed' : 'readiness.media.variant_coverage.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'product_media',
                section: null,
                routeName: 'catalog.products.variants.index',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Variant images',
            ),
            safeContext: [
                'total_variants' => $totalVariants,
                'variants_without_media' => count($variantsWithoutMedia),
            ],
        );
    }
}
