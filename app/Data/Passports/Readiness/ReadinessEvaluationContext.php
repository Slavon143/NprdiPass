<?php

namespace App\Data\Passports\Readiness;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use Carbon\CarbonImmutable;

readonly class ReadinessEvaluationContext
{
    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @param  array<int, ProductDocument>  $referencedDocuments
     * @param  array<int, bool>  $storageExistenceResults
     * @param  array<string, mixed>  $config
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
    ) {}
}
