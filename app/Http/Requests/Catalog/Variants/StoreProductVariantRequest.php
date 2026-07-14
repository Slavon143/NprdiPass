<?php

namespace App\Http\Requests\Catalog\Variants;

use App\Models\Catalog\ProductVariant;

class StoreProductVariantRequest extends ProductVariantRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $product = $this->routeProductOrFail();

        return $actor !== null && $product !== null
            && $actor->can('create', [ProductVariant::class, $product]);
    }
}
