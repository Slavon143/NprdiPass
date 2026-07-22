<?php

namespace App\Services\Passports\Readiness;

use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Services\Passports\DppPayloadNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class ReadinessContextBuilder
{
    private DppPayloadNormalizer $normalizer;

    public function __construct(
        DppPayloadNormalizer $normalizer,
        private readonly ReadinessProfileRepository $profileRepository,
    ) {
        $this->normalizer = $normalizer;
    }

    public function build(Company $company, Product $product, ?CarbonImmutable $evaluationDate = null): ReadinessEvaluationContext
    {
        $product->loadMissing([
            'categories',
            'variants' => function ($query) {
                $query->with('attributeValues.definition');
            },
            'defaultVariant',
            'attributeValues.definition',
            'productMedia',
        ]);

        $passport = $product->passport;

        $currentDraft = null;
        $normalizedPayload = [];
        $referencedDocuments = [];
        $storageExistenceResults = [];

        if ($passport !== null) {
            $passport->loadMissing('currentDraftVersion');
            /** @var ProductPassport $passport */
            /** @var ProductPassportVersion|null $currentDraft */
            $currentDraft = $passport->currentDraftVersion;
            if (! $currentDraft instanceof ProductPassportVersion) {
                $currentDraft = null;
            }

            if ($currentDraft !== null) {
                $normalizedPayload = $this->normalizer->normalize($currentDraft->payload);

                if (! empty($normalizedPayload['document_references'])) {
                    $documentUuids = array_values(array_unique(array_map(
                        fn (array $ref): string => $ref['document_uuid'],
                        $normalizedPayload['document_references'],
                    )));

                    if ($documentUuids !== []) {
                        $documents = ProductDocument::query()
                            ->forCompany($company)
                            ->with('currentVersion')
                            ->whereIn('uuid', $documentUuids)
                            ->get()
                            ->keyBy('uuid');

                        foreach ($documentUuids as $uuid) {
                            $referencedDocuments[] = $documents[$uuid] ?? null;
                        }

                        foreach ($referencedDocuments as $doc) {
                            $exists = false;

                            if ($doc !== null && $doc->currentVersion !== null) {
                                $exists = Storage::disk('product_documents')->exists(
                                    $doc->currentVersion->storage_key,
                                );
                            }

                            $storageExistenceResults[] = $exists;
                        }
                    }
                }
            }
        }

        $profile = $currentDraft instanceof ProductPassportVersion
            && is_string($currentDraft->readiness_profile ?? null)
            && is_int($currentDraft->readiness_profile_version ?? null)
                ? $this->profileRepository->for($currentDraft->readiness_profile, $currentDraft->readiness_profile_version)
                : $this->profileRepository->active();

        return new ReadinessEvaluationContext(
            company: $company,
            product: $product,
            passport: $passport instanceof ProductPassport ? $passport : null,
            currentDraft: $currentDraft,
            normalizedPayload: $normalizedPayload,
            referencedDocuments: $referencedDocuments,
            storageExistenceResults: $storageExistenceResults,
            evaluationDate: $evaluationDate ?? new CarbonImmutable,
            readinessProfile: $profile,
            config: config('passport_readiness'),
        );
    }
}
