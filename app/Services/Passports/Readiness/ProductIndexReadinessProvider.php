<?php

namespace App\Services\Passports\Readiness;

use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\Passports\PassportValidationRun;
use App\Services\Billing\CommercialAccessPolicy;
use App\Services\Dpp\DppReadinessProfileBatch;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductIndexReadinessProvider
{
    public function __construct(
        private readonly ReadinessContextBuilder $contextBuilder,
        private readonly PassportReadinessEvaluator $evaluator,
    ) {}

    /**
     * @param  LengthAwarePaginator<int, Product>  $products
     * @return array<string, array<string, mixed>>
     */
    public function loadSummaries(Company $company, LengthAwarePaginator $products): array
    {
        $productIds = $products->pluck('id')->all();

        if ($productIds === []) {
            return [];
        }

        $items = new EloquentCollection($products->items());
        $items->loadMissing('passport.currentDraftVersion');
        $readOnly = app(CommercialAccessPolicy::class)->isReadOnly($company);
        $eligible = $items->filter(fn (Product $product): bool => ! $readOnly
            && $product->managed && $product->status !== ProductStatus::Archived);
        $eligible->loadMissing([
            'categories',
            'variants.attributeValues.definition',
            'defaultVariant',
            'attributeValues.definition',
            'productMedia',
            'media',
            'primaryMedia',
            'passport.currentDraftVersion',
        ]);
        $legacy = $eligible->filter(fn ($product) => $product->passport !== null
            && ! is_array($product->passport->currentDraftVersion?->readiness_profile_snapshot));
        $profileBatch = $legacy->isEmpty() ? null : DppReadinessProfileBatch::load($legacy);
        $productAttributeDefinitionIds = $eligible->isEmpty() ? [] : AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $summaries = [];
        $documentUuids = [];
        foreach ($eligible as $product) {
            foreach ($product->passport?->currentDraftVersion?->payload['document_references'] ?? [] as $reference) {
                if (is_string($reference['document_uuid'] ?? null)) {
                    $documentUuids[] = $reference['document_uuid'];
                }
            }
        }
        $documents = $documentUuids === [] ? [] : ProductDocument::query()->forCompany($company)
            ->whereIn('product_id', $productIds)->whereIn('uuid', array_unique($documentUuids))
            ->with(['versions' => fn ($query) => $query->where('company_id', $company->getKey())])->get()->keyBy('uuid')->all();
        foreach ($documents as $document) {
            $document->setRelation('readinessVersions', $document->versions);
        }

        $limitedPassportIds = $items->reject(fn (Product $product): bool => $eligible->contains($product))
            ->pluck('passport.id')->filter()->values()->all();
        $latestRuns = PassportValidationRun::query()->where('company_id', $company->getKey())
            ->whereIn('passport_id', $limitedPassportIds)->selectRaw('MAX(id)')->groupBy('passport_id');
        $stored = $limitedPassportIds === [] ? collect() : PassportValidationRun::query()
            ->where('company_id', $company->getKey())->whereIn('id', $latestRuns)
            ->get(['id', 'passport_id', 'draft_revision', 'status', 'score', 'blocker_count', 'warning_count', 'recommendation_count', 'validated_at'])
            ->keyBy('passport_id');

        foreach ($products as $product) {
            $passport = $product->passport;

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

            if (! $eligible->contains($product)) {
                $run = $stored->get($passport->getKey());
                $summaries[$product->uuid] = [
                    'passport_status' => $passport->status->value,
                    'passport_revision' => $run?->draft_revision,
                    'readiness_status' => $run?->status,
                    'score' => $run?->score,
                    'blockers' => (int) ($run->blocker_count ?? 0),
                    'warnings' => (int) ($run->warning_count ?? 0),
                    'recommendations' => (int) ($run->recommendation_count ?? 0),
                    'passport_uuid' => $passport->uuid,
                    'readiness_source' => $run === null ? 'unavailable' : 'stored',
                    'evaluated_at' => $run?->validated_at?->toISOString(),
                ];

                continue;
            }

            $context = $this->contextBuilder->build($company, $product, documentBatch: $documents, profileBatch: $profileBatch, productAttributeDefinitionIds: $productAttributeDefinitionIds);
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
                'readiness_source' => 'live',
            ];
        }

        return $summaries;
    }
}
