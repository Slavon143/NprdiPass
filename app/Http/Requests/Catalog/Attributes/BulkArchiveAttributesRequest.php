<?php

namespace App\Http\Requests\Catalog\Attributes;

use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

class BulkArchiveAttributesRequest extends FormRequest
{
    public const MAX_ATTRIBUTES = 100;

    public function authorize(): bool
    {
        $company = app(CurrentCompany::class)->get();

        return $company instanceof Company
            && $this->user()?->can(CompanyPermission::CatalogManageAttributes->value, $company) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attributes' => ['required', 'array', 'min:1', 'max:'.self::MAX_ATTRIBUTES],
            'attributes.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
