<?php

namespace App\Http\Requests\Catalog\Categories;

use App\Models\Catalog\Category;

class ReorderCategoriesRequest extends CategoryRequest
{
    public function authorize(): bool
    {
        $actor = $this->actor();
        $company = $this->currentCompany();

        return $actor !== null && $company !== null
            && $actor->can('reorder', [Category::class, $company]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'parent_uuid' => ['nullable', 'uuid'],
            'ordered_uuids' => ['required', 'array', 'min:1', 'max:500'],
            'ordered_uuids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['parent_uuid' => $this->cleanNullableString('parent_uuid')]);
    }
}
