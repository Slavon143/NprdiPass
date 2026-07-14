<?php

namespace App\Http\Requests\Catalog\Attributes;

use App\Models\Catalog\AttributeOption;

class ReorderAttributeOptionsRequest extends AttributeRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $definition = $this->definition();

        return $actor !== null && $definition !== null && $actor->can('reorder', [AttributeOption::class, $definition]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'option_ids' => ['required', 'array', 'max:200'],
            'option_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
