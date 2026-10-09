<?php

namespace App\Services\Passports\Readiness;

use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Services\Billing\CommercialAccessPolicy;

class PassportReadinessDisplay
{
    /** @phpstan-impure */
    public function canEvaluate(Company $company, Product $product): bool
    {
        return $product->managed && $product->status !== ProductStatus::Archived
            && ! app(CommercialAccessPolicy::class)->isReadOnly($company);
    }

    public function result(Company $company, Product $product): ?PassportReadinessResult
    {
        if (! $this->canEvaluate($company, $product)) {
            return app(StoredPassportReadiness::class)->latest($company, $product);
        }

        return app(PassportReadinessEvaluator::class)->evaluate(
            app(ReadinessContextBuilder::class)->build($company, $product),
        );
    }
}
