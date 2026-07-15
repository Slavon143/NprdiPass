<?php

namespace App\Http\Controllers\Api\V1\Catalog\Concerns;

use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;

trait ResolvesCatalogApiResources
{
    protected function currentCompany(TokenCurrentCompany $currentCompany): Company
    {
        return $currentCompany->require();
    }

    protected function resolveCategory(Company $company, string $uuid): Category
    {
        $category = Category::query()->forCompany($company)->where('uuid', $uuid)->first();

        return $category instanceof Category ? $category : throw new ModelNotFoundException;
    }

    protected function resolveProduct(Company $company, string $uuid): Product
    {
        $product = Product::query()->forCompany($company)->where('uuid', $uuid)->first();

        return $product instanceof Product ? $product : throw new ModelNotFoundException;
    }

    protected function resolveVariant(Company $company, Product $product, string $uuid): ProductVariant
    {
        $variant = $product->variants()->where('uuid', $uuid)->first();

        return $variant instanceof ProductVariant ? $variant : throw new ModelNotFoundException;
    }

    protected function resolveAttributeDefinition(Company $company, string $uuid): AttributeDefinition
    {
        $definition = AttributeDefinition::query()->forCompany($company)->where('uuid', $uuid)->first();

        return $definition instanceof AttributeDefinition ? $definition : throw new ModelNotFoundException;
    }

    protected function resolveAttributeOption(AttributeDefinition $definition, int $id): AttributeOption
    {
        $option = $definition->options()->where('id', $id)->first();

        return $option instanceof AttributeOption ? $option : throw new ModelNotFoundException;
    }

    protected function resolveProductMedia(Company $company, Product $product, string $uuid): ProductMedia
    {
        $media = ProductMedia::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('product_variant_id', null)
            ->where('uuid', $uuid)
            ->first();

        return $media instanceof ProductMedia ? $media : throw new ModelNotFoundException;
    }

    protected function resolveVariantMedia(Company $company, Product $product, ProductVariant $variant, string $uuid): ProductMedia
    {
        $media = ProductMedia::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->where('uuid', $uuid)
            ->first();

        return $media instanceof ProductMedia ? $media : throw new ModelNotFoundException;
    }

    protected function resolveAnyMedia(Company $company, string $uuid): ProductMedia
    {
        $media = ProductMedia::query()
            ->forCompany($company)
            ->where('uuid', $uuid)
            ->first();

        return $media instanceof ProductMedia ? $media : throw new ModelNotFoundException;
    }
}
