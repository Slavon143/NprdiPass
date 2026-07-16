<?php

namespace App\Http\Requests\Catalog\Documents;

use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class DocumentRequest extends FormRequest
{
    protected function currentCompany(): ?Company
    {
        $company = $this->attributes->get('currentCompany');

        return $company instanceof Company ? $company : null;
    }

    protected function actor(): ?User
    {
        $user = $this->user();

        return $user instanceof User ? $user : null;
    }

    protected function routeProductOrFail(): ?Product
    {
        $uuid = $this->route('product');
        if ($uuid === null) {
            return null;
        }

        $company = $this->currentCompany();
        if ($company === null) {
            return null;
        }

        return Product::query()->forCompany($company)->where('uuid', $uuid)->first();
    }

    protected function cleanString(string $key): string
    {
        $value = $this->input($key);
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    protected function cleanNullableString(string $key): ?string
    {
        $value = $this->input($key);
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
