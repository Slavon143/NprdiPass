<?php

namespace App\Http\Requests\Catalog\Media;

use App\Models\Catalog\ProductMedia;

class StoreVariantMediaRequest extends StoreProductMediaRequest
{
    public function authorize(): bool
    {
        $product = $this->product();
        $variant = $product === null ? null : $this->variant($product);

        return $variant !== null && $this->user()?->can('createForVariant', [ProductMedia::class, $variant]) === true;
    }
}
