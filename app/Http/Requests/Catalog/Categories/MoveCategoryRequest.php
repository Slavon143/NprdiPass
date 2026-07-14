<?php

namespace App\Http\Requests\Catalog\Categories;

class MoveCategoryRequest extends CategoryRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $category = $this->routeCategoryOrFail();

        return $actor !== null && $category !== null && $actor->can('move', $category);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['parent_uuid' => ['nullable', 'uuid']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['parent_uuid' => $this->cleanNullableString('parent_uuid')]);
    }
}
