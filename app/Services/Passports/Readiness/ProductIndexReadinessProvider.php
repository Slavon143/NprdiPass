<?php

namespace App\Services\Passports\Readiness;

use App\Models\Company;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductIndexReadinessProvider
{
    public function __construct(
        private readonly ReadinessContextBuilder $contextBuilder,
        private readonly PassportReadinessEvaluator $evaluator,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadSummaries(Company $company, LengthAwarePaginator $products): array
    {
        $productIds = $products->pluck('id')->all();

        if ($productIds === []) {
            return [];
        }

        $items = new EloquentCollection($products->items());
        $items->loadMissing([
            'categories',
            'variants.attributeValues.definition',
            'defaultVariant',
            'attributeValues.definition',
            'productMedia',
            'primaryMedia',
            'passport.currentDraftVersion',
        ]);

        $summaries = [];

        foreach ($products as $product) {
            $context = $this->contextBuilder->build($company, $product);
            $passport = $context->passport;

            if ($passport === null) {
                $summaries[$product->uuid] = [
                    'passport_status' => 'not_created',
                    'passport_revision' => null,
                    'readiness_status' => null,
                    'score' => null,
                    'blockers' => 0,
                    'warnings' => 0,
                    'recommendations' => 0,
                    'passport_uuid' => null,
                ];

                continue;
            }

            $result = $this->evaluator->evaluate($context);

            $summaries[$product->uuid] = [
                'passport_status' => $passport->status->value,
                'passport_revision' => $context->currentDraft !== null ? $context->currentDraft->draft_revision : null,
                'readiness_status' => $result->status->value,
                'score' => $result->score,
                'blockers' => $result->counts->blockers,
                'warnings' => $result->counts->warnings,
                'recommendations' => $result->counts->recommendations,
                'passport_uuid' => $passport->uuid,
            ];
        }

        return $summaries;
    }
}
