<?php

namespace App\Http\Requests\Catalog\Categories;

use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class CategoryRequest extends FormRequest
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

    protected function routeCategory(): ?Category
    {
        $company = $this->currentCompany();
        $uuid = $this->route('category');

        if ($company === null || ! is_string($uuid)) {
            return null;
        }

        return Category::query()->forCompany($company)->where('uuid', $uuid)->first();
    }

    protected function routeCategoryOrFail(): ?Category
    {
        $company = $this->currentCompany();
        $uuid = $this->route('category');

        if ($company === null || ! is_string($uuid)) {
            return null;
        }

        return Category::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
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
}
