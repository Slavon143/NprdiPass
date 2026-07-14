<?php

namespace App\Http\Requests\Catalog\Variants;

class UpdateProductVariantRequest extends ProductVariantRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $product = $this->routeProductOrFail();
        $variant = $product === null ? null : $this->routeVariantOrFail($product);

        return $actor !== null && $variant !== null && $actor->can('update', $variant);
    }
}
