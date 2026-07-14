<?php

namespace App\Http\Requests\Catalog\Categories;

use App\Models\Catalog\Category;

class StoreCategoryRequest extends CategoryRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $company = $this->currentCompany();

        return $actor !== null && $company !== null
            && $actor->can('create', [Category::class, $company]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_uuid' => ['nullable', 'uuid'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->cleanString('name'),
            'slug' => $this->cleanNullableString('slug'),
            'description' => $this->cleanNullableString('description'),
            'parent_uuid' => $this->cleanNullableString('parent_uuid'),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }
}
