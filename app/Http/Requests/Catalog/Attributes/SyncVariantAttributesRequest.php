<?php

namespace App\Http\Requests\Catalog\Attributes;

class SyncVariantAttributesRequest extends AttributeRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $product = $this->product();
        $variant = $product === null ? null : $this->variant($product);

        return $actor !== null && $variant !== null && $actor->can('manageAttributes', $variant);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['attributes' => ['present', 'array', 'max:500']];
    }
}
