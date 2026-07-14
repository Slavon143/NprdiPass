<?php

namespace App\Http\Requests\Catalog\Categories;

class UpdateCategoryRequest extends CategoryRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $category = $this->routeCategoryOrFail();

        return $actor !== null && $category !== null && $actor->can('update', $category);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->cleanString('name'),
            'slug' => $this->cleanString('slug'),
            'description' => $this->cleanNullableString('description'),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }
}
