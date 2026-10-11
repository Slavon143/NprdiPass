<?php

namespace App\Data\Passports\Readiness;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\DppProfile;
use App\Models\DppRequirement;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use Carbon\CarbonImmutable;

readonly class ReadinessEvaluationContext
{
    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @param  array<int, ProductDocument>  $referencedDocuments
     * @param  array<string, bool>  $storageExistenceResults
     * @param  array<string, mixed>  $config
     * @param  array<int, DppProfile>  $dppProfiles
     * @param  array<int, DppRequirement>  $effectiveRequirements
     * @param  array<int, int>|null  $productAttributeDefinitionIds
     */
    public function __construct(
        public Company $company,
        public ?Product $product,
        public ?ProductPassport $passport,
        public ?ProductPassportVersion $currentDraft,
        public array $normalizedPayload,
        public array $referencedDocuments,
        public array $storageExistenceResults,
        public CarbonImmutable $evaluationDate,
        public ?ReadinessProfileDefinition $readinessProfile = null,
        public array $config = [],
        public array $dppProfiles = [],
        public array $effectiveRequirements = [],
        public array $publicationProfileCheck = [],
        public ?array $productAttributeDefinitionIds = null,
    ) {}

    /** @return array<string, mixed> */
    public function sectionData(string $section): array
    {
        $data = $this->normalizedPayload['data'][$section] ?? [];
        $defaultLanguage = $this->passport === null ? 'sv' : $this->passport->default_language;
        $translations = $this->normalizedPayload['translations'][$defaultLanguage][$section]
            ?? $this->normalizedPayload['translations']['sv'][$section]
            ?? [];

        return array_replace(
            is_array($data) ? $data : [],
            is_array($translations) ? $translations : [],
        );
    }

    public function fieldValue(string $section, string $field): mixed
    {
        return $this->sectionData($section)[$field] ?? null;
    }
}
