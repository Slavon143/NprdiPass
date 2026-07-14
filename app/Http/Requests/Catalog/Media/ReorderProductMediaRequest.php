<?php

namespace App\Http\Requests\Catalog\Media;

class ReorderProductMediaRequest extends MediaRequest
{
    public function authorize(): bool
    {
        $product = $this->product();

        return $product !== null && $this->user()?->can('manageMedia', $product) === true;
    }

    public function rules(): array
    {
        return ['media_uuids' => ['present', 'array', 'max:'.config('catalog.media.max_per_product')], 'media_uuids.*' => ['required', 'uuid', 'distinct']];
    }
}
