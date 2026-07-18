<?php

namespace App\Http\Requests\Catalog\Products;

use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

class BulkArchiveProductsRequest extends FormRequest
{
    public const MAX_PRODUCTS = 100;

    public function authorize(): bool
    {
        $company = app(CurrentCompany::class)->get();

        return $company instanceof Company
            && $this->user()?->can(CompanyPermission::CatalogArchive->value, $company) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'products' => ['required', 'array', 'min:1', 'max:'.self::MAX_PRODUCTS],
            'products.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
