<?php

namespace App\Http\Requests\Catalog\Variants;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class ProductVariantRequest extends FormRequest
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

    protected function routeVariantOrFail(Product $product): ?ProductVariant
    {
        $company = $this->currentCompany();
        $uuid = $this->route('variant');

        if ($company === null || ! is_string($uuid)) {
            return null;
        }

        return ProductVariant::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'gtin' => ['nullable', 'string'],
            'mpn' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->cleanNullableString('name'),
            'sku' => $this->cleanNullableString('sku'),
            'gtin' => $this->cleanNullableString('gtin'),
            'mpn' => $this->cleanNullableString('mpn'),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }

    private function cleanNullableString(string $key): mixed
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
