<?php

namespace App\Http\Requests\Catalog\Media;

use App\Models\Catalog\ProductMedia;

class StoreProductMediaRequest extends MediaRequest
{
    public function authorize(): bool
    {
        $product = $this->product();

        return $product !== null && $this->user()?->can('createForProduct', [ProductMedia::class, $product]) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareMetadata();
    }

    public function rules(): array
    {
        return ['image' => ['required', 'file', 'max:'.config('catalog.media.max_file_size_kb')], 'alt_text' => ['nullable', 'string', 'max:'.config('catalog.media.alt_text_max')], 'caption' => ['nullable', 'string', 'max:'.config('catalog.media.caption_max')], 'make_primary' => ['boolean'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295']];
    }
}
