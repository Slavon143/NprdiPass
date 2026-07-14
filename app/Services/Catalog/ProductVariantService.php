<?php

namespace App\Services\Catalog;

use App\Exceptions\Catalog\VariantOperationException;
use App\Models\Catalog\Product;

class ProductVariantService
{
    public const MAX_VARIANTS_PER_PRODUCT = 100;

    public function assertCapacity(Product $lockedProduct): void
    {
        if ($lockedProduct->variants()->count() >= self::MAX_VARIANTS_PER_PRODUCT) {
            throw VariantOperationException::limitReached();
        }
    }
}
