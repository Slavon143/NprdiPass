<?php

namespace App\Http\Requests\Catalog\Products;

use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateProductsStatusRequest extends FormRequest
{
    public const MAX_PRODUCTS = 100;

    public function authorize(): bool
    {
        $company = app(CurrentCompany::class)->get();

        if (! $company instanceof Company) {
            return false;
        }

        return match ($this->input('operation')) {
            'activate', 'draft' => $this->user()?->can(CompanyPermission::CatalogPublish->value, $company) === true,
            'archive', 'restore' => $this->user()?->can(CompanyPermission::CatalogArchive->value, $company) === true,
            default => false,
        };
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(['activate', 'draft', 'archive', 'restore'])],
            'products' => ['required', 'array', 'min:1', 'max:'.self::MAX_PRODUCTS],
            'products.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
