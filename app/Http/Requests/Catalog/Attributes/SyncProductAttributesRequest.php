<?php

namespace App\Http\Requests\Catalog\Attributes;

class SyncProductAttributesRequest extends AttributeRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $product = $this->product();

        return $actor !== null && $product !== null && $actor->can('manageAttributes', $product);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['attributes' => ['present', 'array', 'max:500']];
    }
}
