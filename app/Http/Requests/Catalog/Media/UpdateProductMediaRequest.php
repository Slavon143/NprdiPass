<?php

namespace App\Http\Requests\Catalog\Media;

class UpdateProductMediaRequest extends MediaRequest
{
    public function authorize(): bool
    {
        $product = $this->product();
        $variant = $this->route('variant') !== null && $product !== null ? $this->variant($product) : null;
        $media = $product === null ? null : $this->media($product, $variant);

        return $media !== null && $this->user()?->can('update', $media) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareMetadata();
    }

    public function rules(): array
    {
        return ['alt_text' => ['nullable', 'string', 'max:'.config('catalog.media.alt_text_max')], 'caption' => ['nullable', 'string', 'max:'.config('catalog.media.caption_max')], 'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295']];
    }
}
