<?php

namespace App\Http\Controllers\Catalog\Concerns;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

trait ResolvesCatalogMedia
{
    private function resolveProduct(Company $company, string $uuid): Product
    {
        return Product::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function resolveVariant(Company $company, Product $product, string $uuid): ProductVariant
    {
        return ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->where('uuid', $uuid)->firstOrFail();
    }

    private function resolveMedia(Company $company, Product $product, string $uuid, ?ProductVariant $variant = null): ProductMedia
    {
        $query = ProductMedia::query()->forCompany($company)->where('product_id', $product->getKey())->where('uuid', $uuid);
        $variant === null ? $query->whereNull('product_variant_id') : $query->where('product_variant_id', $variant->getKey());

        return $query->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
