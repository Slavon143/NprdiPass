<?php

namespace App\Actions\Catalog\Products;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;

class ProductAggregateCreator
{
    private const PRODUCT_FIELDS = [
        'name',
        'short_description',
        'description',
        'brand',
        'manufacturer',
    ];

    /**
     * Create the mandatory Product + default Variant aggregate.
     * The caller owns validation, normalization, authorization, transaction, and audit.
     *
     * @param  array<string, mixed>  $productData
     * @param  array<string, mixed>  $variantData
     */
    public function create(User $actor, Company $company, array $productData, array $variantData): Product
    {
        $variantName = trim((string) ($variantData['name'] ?? ''));
        $product = new Product;
        $product->fill(array_intersect_key($productData, array_flip(self::PRODUCT_FIELDS)));
        $product->forceFill([
            'company_id' => $company->getKey(),
            'name' => $productData['name'],
            'slug' => $productData['slug'],
            'slug_normalized' => $productData['slug'],
            'status' => ProductStatus::Draft,
            'default_variant_id' => null,
            'primary_category_id' => null,
            'primary_media_id' => null,
            'published_at' => null,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'name' => $variantName === '' ? 'Default' : $variantName,
            'sku' => $variantData['sku'] ?? null,
            'sku_normalized' => $variantData['sku_normalized'] ?? null,
            'gtin' => $variantData['gtin'] ?? null,
            'mpn' => $variantData['mpn'] ?? null,
            'status' => ProductVariantStatus::Draft,
            'sort_order' => $variantData['sort_order'] ?? 0,
            'primary_media_id' => null,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        $product->forceFill(['default_variant_id' => $variant->getKey()])->save();

        return $product->refresh()->load('defaultVariant');
    }
}
