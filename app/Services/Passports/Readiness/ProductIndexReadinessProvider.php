<?php

namespace App\Services\Passports\Readiness;

use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Services\Passports\DppPayloadNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductIndexReadinessProvider
{
    public function __construct(
        private readonly DppPayloadNormalizer $normalizer,
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

        $passports = ProductPassport::whereIn('product_id', $productIds)
            ->with('currentDraftVersion')
            ->get()
            ->keyBy('product_id');

        $allDocumentUuids = [];
        $normalizedPayloads = [];

        foreach ($passports as $productId => $passport) {
            /** @var ProductPassportVersion|null $draft */
            $draft = $passport->currentDraftVersion;
            if ($draft !== null) {
                $normalized = $this->normalizer->normalize($draft->payload);
                $normalizedPayloads[$productId] = $normalized;

                if (! empty($normalized['document_references'])) {
                    foreach ($normalized['document_references'] as $ref) {
                        if (isset($ref['document_uuid']) && is_string($ref['document_uuid']) && $ref['document_uuid'] !== '') {
                            $allDocumentUuids[] = $ref['document_uuid'];
                        }
                    }
                }
            }
        }

        $allDocumentUuids = array_values(array_unique($allDocumentUuids));
        $documents = [];
        if ($allDocumentUuids !== []) {
            $documents = ProductDocument::query()
                ->forCompany($company)
                ->with('currentVersion')
                ->whereIn('uuid', $allDocumentUuids)
                ->get()
                ->keyBy('uuid');
        }

        $items = new EloquentCollection($products->items());
        $items->loadMissing([
            'categories',
            'variants',
            'defaultVariant',
            'attributeValues.definition',
            'productMedia',
            'primaryMedia',
        ]);

        $config = config('passport_readiness');
        $summaries = [];

        foreach ($products as $product) {
            $productId = $product->getKey();
            $passport = $passports[$productId] ?? null;

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

            $product->setRelation('passport', $passport);

            $currentDraft = $passport->currentDraftVersion;
            if (! $currentDraft instanceof ProductPassportVersion) {
                $currentDraft = null;
            }

            $normalizedPayload = $normalizedPayloads[$productId] ?? [];

            $referencedDocuments = [];
            $storageExistenceResults = [];
            if (! empty($normalizedPayload['document_references'])) {
                foreach ($normalizedPayload['document_references'] as $ref) {
                    $uuid = $ref['document_uuid'] ?? '';
                    $doc = $documents[$uuid] ?? null;
                    $referencedDocuments[] = $doc;
                    $storageExistenceResults[] = true;
                }
            }

            $context = new ReadinessEvaluationContext(
                company: $company,
                product: $product,
                passport: $passport,
                currentDraft: $currentDraft,
                normalizedPayload: $normalizedPayload,
                referencedDocuments: $referencedDocuments,
                storageExistenceResults: $storageExistenceResults,
                evaluationDate: new CarbonImmutable,
                config: $config,
            );

            $result = $this->evaluator->evaluate($context);

            $summaries[$product->uuid] = [
                'passport_status' => $passport->status->value,
                'passport_revision' => $currentDraft !== null ? $currentDraft->draft_revision : null,
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
