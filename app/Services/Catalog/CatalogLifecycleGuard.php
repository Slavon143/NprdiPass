<?php

namespace App\Services\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Exceptions\Catalog\LifecycleOperationException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;

class CatalogLifecycleGuard
{
    public function assertProductEditable(Product $product): void
    {
        if ($product->status === ProductStatus::Archived) {
            throw LifecycleOperationException::archivedImmutable();
        }
    }

    public function assertVariantEditable(Product $product, ProductVariant $variant): void
    {
        $this->assertProductEditable($product);

        if ($variant->status === ProductVariantStatus::Archived) {
            throw LifecycleOperationException::archivedImmutable();
        }
    }
}
