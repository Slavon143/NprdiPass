<?php

namespace App\Http\Requests\Catalog\Media;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

abstract class MediaRequest extends FormRequest
{
    protected function company(): ?Company
    {
        $value = $this->attributes->get('currentCompany');

        return $value instanceof Company ? $value : null;
    }

    protected function product(): ?Product
    {
        $company = $this->company();
        $uuid = $this->route('product');

        return $company !== null && is_string($uuid) ? Product::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail() : null;
    }

    protected function variant(?Product $product = null): ?ProductVariant
    {
        $company = $this->company();
        $uuid = $this->route('variant');
        $product ??= $this->product();

        return $company !== null && $product !== null && is_string($uuid)
            ? ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->where('uuid', $uuid)->firstOrFail() : null;
    }

    protected function media(Product $product, ?ProductVariant $variant): ?ProductMedia
    {
        $company = $this->company();
        $uuid = $this->route('media');
        if ($company === null || ! is_string($uuid)) {
            return null;
        }
        $query = ProductMedia::query()->forCompany($company)->where('product_id', $product->getKey())->where('uuid', $uuid);
        $variant === null ? $query->whereNull('product_variant_id') : $query->where('product_variant_id', $variant->getKey());

        return $query->firstOrFail();
    }

    protected function prepareMetadata(): void
    {
        $this->merge(['alt_text' => $this->clean('alt_text'), 'caption' => $this->clean('caption'), 'make_primary' => $this->boolean('make_primary')]);
    }

    private function clean(string $key): ?string
    {
        $value = $this->input($key);
        if (! is_string($value)) {
            return null;
        } $value = trim($value);

        return $value === '' ? null : $value;
    }
}
