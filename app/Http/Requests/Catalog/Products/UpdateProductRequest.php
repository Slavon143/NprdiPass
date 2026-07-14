<?php

namespace App\Http\Requests\Catalog\Products;

use App\Services\Catalog\ProductCategoryService;

class UpdateProductRequest extends ProductRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $product = $this->routeProductOrFail();

        return $actor !== null && $product !== null && $actor->can('update', $product);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'brand' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'primary_category_uuid' => ['nullable', 'uuid'],
            'category_uuids' => ['array', 'max:'.ProductCategoryService::MAX_CATEGORIES_PER_PRODUCT],
            'category_uuids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->cleanString('name'),
            'slug' => $this->cleanString('slug'),
            'short_description' => $this->cleanNullableString('short_description'),
            'description' => $this->cleanNullableString('description'),
            'brand' => $this->cleanNullableString('brand'),
            'manufacturer' => $this->cleanNullableString('manufacturer'),
            'primary_category_uuid' => $this->cleanNullableString('primary_category_uuid'),
            'category_uuids' => $this->categoryUuids(),
        ]);
    }
}
