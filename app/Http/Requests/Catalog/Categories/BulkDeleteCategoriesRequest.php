<?php

namespace App\Http\Requests\Catalog\Categories;

use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteCategoriesRequest extends FormRequest
{
    public const MAX_CATEGORIES = 100;

    public function authorize(): bool
    {
        $company = app(CurrentCompany::class)->get();

        return $company instanceof Company
            && $this->user()?->can(CompanyPermission::CatalogManageCategories->value, $company) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array', 'min:1', 'max:'.self::MAX_CATEGORIES],
            'categories.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
