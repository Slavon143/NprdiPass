<?php

namespace App\Http\Requests\Catalog\Media;

class ReorderVariantMediaRequest extends MediaRequest
{
    public function authorize(): bool
    {
        $product = $this->product();
        $variant = $product === null ? null : $this->variant($product);

        return $variant !== null && $this->user()?->can('manageMedia', $variant) === true;
    }

    public function rules(): array
    {
        return ['media_uuids' => ['present', 'array', 'max:'.config('catalog.media.max_per_variant')], 'media_uuids.*' => ['required', 'uuid', 'distinct']];
    }
}
