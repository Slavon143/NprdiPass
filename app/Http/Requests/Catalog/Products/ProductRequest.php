<?php

namespace App\Http\Requests\Catalog\Products;

use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class ProductRequest extends FormRequest
{
    protected function currentCompany(): ?Company
    {
        $company = $this->attributes->get('currentCompany');

        return $company instanceof Company ? $company : null;
    }

    protected function actor(): ?User
    {
        $actor = $this->user();

        return $actor instanceof User ? $actor : null;
    }

    protected function routeProductOrFail(): ?Product
    {
        $company = $this->currentCompany();
        $uuid = $this->route('product');

        if ($company === null || ! is_string($uuid)) {
            return null;
        }

        return Product::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    protected function cleanString(string $key): string
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : '';
    }

    protected function cleanNullableString(string $key): ?string
    {
        $value = $this->cleanString($key);

        return $value === '' ? null : $value;
    }

    /** @return list<mixed> */
    protected function categoryUuids(): array
    {
        $value = $this->input('category_uuids', []);

        return is_array($value) ? array_values($value) : [$value];
    }
}
