<?php

namespace App\Services\Passports\Readiness;

use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessProfileDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\DppProfile;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Services\Catalog\Documents\ProductDocumentCurrentVersionResolver;
use App\Services\Dpp\DppDraftProfileSnapshot;
use App\Services\Dpp\DppProfileResolver;
use App\Services\Dpp\DppPublicationProfileCheck;
use App\Services\Dpp\DppReadinessProfileBatch;
use App\Services\Dpp\EffectiveDppRequirementSet;
use App\Services\Passports\DppPayloadNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class ReadinessContextBuilder
{
    private DppPayloadNormalizer $normalizer;

    public function __construct(
        DppPayloadNormalizer $normalizer,
        private readonly ProductDocumentCurrentVersionResolver $documentVersionResolver,
        private readonly ReadinessProfileRepository $profileRepository,
        private readonly DppProfileResolver $dppProfileResolver,
        private readonly EffectiveDppRequirementSet $requirementSet,
    ) {
        $this->normalizer = $normalizer;
    }

    /** @param array<string, ProductDocument>|null $documentBatch */
    public function build(Company $company, Product $product, ?CarbonImmutable $evaluationDate = null, ?array $documentBatch = null, ?DppReadinessProfileBatch $profileBatch = null): ReadinessEvaluationContext
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
                        $documents = $documentBatch !== null ? collect($documentBatch) : ProductDocument::query()
                            ->forCompany($company)
                            ->where('product_id', $product->getKey())
                            ->with('currentVersion')
                            ->whereIn('uuid', $documentUuids)
                            ->get()
                            ->keyBy('uuid');

                        $references = collect($normalizedPayload['document_references'])->keyBy('document_uuid');
                        foreach ($documentUuids as $uuid) {
                            $doc = $documents[$uuid] ?? null;
                            if ($doc !== null && ((int) $doc->company_id !== (int) $company->getKey()
                                || (int) $doc->product_id !== (int) $product->getKey())) {
                                $doc = null;
                            }
                            if ($doc !== null) {
                                $doc->setRelation('readinessLatestVersion', $this->documentVersionResolver->resolve($doc, true, $evaluationDate));
                                $doc->setRelation('currentVersion', $this->documentVersionResolver->resolveReference(
                                    $doc, $references[$uuid]['document_version_uuid'] ?? null, $evaluationDate,
                                ));
                                $referencedDocuments[] = $doc;
                            }
                        }

                        foreach ($referencedDocuments as $doc) {
                            $exists = false;

                            if ($doc->currentVersion !== null) {
                                $exists = Storage::disk('product_documents')->exists(
                                    $doc->currentVersion->storage_key,
                                );
                            }

                            $storageExistenceResults[$doc->uuid] = $exists;
                        }
                    }
                }
            }
        }

        $evaluationConfig = config('passport_readiness');
        if (is_array($currentDraft?->readiness_profile_snapshot)) {
            $snapshot = $currentDraft->readiness_profile_snapshot;
            $profiles = app(DppDraftProfileSnapshot::class)->profiles($snapshot);
            $profile = new ReadinessProfileDefinition(...$snapshot['definition']);
            $evaluationConfig = $snapshot['config'];
        } else {
            $profiles = $this->dppProfileResolver->resolve($product, $profileBatch?->catalogue);
            if ($currentDraft instanceof ProductPassportVersion
                && is_string($currentDraft->readiness_profile ?? null)
                && is_int($currentDraft->readiness_profile_version ?? null)) {
                $historicalProfile = $profileBatch !== null
                    ? ($profileBatch->catalogue[DppReadinessProfileBatch::key($currentDraft->readiness_profile, $currentDraft->readiness_profile_version)] ?? null)
                    : DppProfile::query()
                        ->where('key', $currentDraft->readiness_profile)
                        ->where('version', $currentDraft->readiness_profile_version)
                        ->first();

                if ($historicalProfile instanceof DppProfile) {
                    $profiles = [$historicalProfile, ...array_values(array_filter($profiles,
                        fn ($selected) => $selected->key !== $historicalProfile->key || $selected->version !== $historicalProfile->version))];
                }
            }

            $profile = $currentDraft instanceof ProductPassportVersion
                && is_string($currentDraft->readiness_profile ?? null)
                && is_int($currentDraft->readiness_profile_version ?? null)
                    ? ($profileBatch?->definition($currentDraft->readiness_profile, $currentDraft->readiness_profile_version, $this->profileRepository)
                        ?? $this->profileRepository->for($currentDraft->readiness_profile, $currentDraft->readiness_profile_version))
                    : (($profiles[0] ?? null) instanceof DppProfile
                        ? ($profileBatch?->definition($profiles[0]->key, $profiles[0]->version, $this->profileRepository)
                            ?? $this->profileRepository->for($profiles[0]->key, $profiles[0]->version))
                        : null);
            $profile ??= $this->profileRepository->active();
        }

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
            config: $evaluationConfig,
            dppProfiles: $profiles,
            effectiveRequirements: $this->requirementSet->resolve($profiles, refresh: $profileBatch === null),
            publicationProfileCheck: $profileBatch === null && $documentBatch === null
                ? app(DppPublicationProfileCheck::class)->check($product, $currentDraft, $profiles)
                : ['status' => 'not_checked', 'reason' => 'batched_preview'],
        );
    }
}
