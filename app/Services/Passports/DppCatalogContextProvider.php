<?php

namespace App\Services\Passports;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;

class DppCatalogContextProvider
{
    /**
     * @return array<string, mixed>
     */
    public function context(Product $product, Company $company): array
    {
        $product->loadMissing([
            'categories',
            'variants' => function ($query) {
                $query->with('attributeValues.definition');
            },
            'defaultVariant',
            'attributeValues.definition',
            'productMedia',
        ]);

        return [
            'product_uuid' => $product->getAttribute('uuid'),
            'product_name' => $product->getAttribute('name'),
            'brand' => $product->getAttribute('brand'),
            'manufacturer' => $product->getAttribute('manufacturer'),
            'status' => $product->status->value,
            'categories' => $product->categories->map(fn ($c) => [
                'uuid' => $c->getAttribute('uuid'),
                'name' => $c->getAttribute('name'),
            ])->values()->toArray(),
            'default_variant' => $this->variantContext($product->defaultVariant),
            'variants' => $product->variants->map(fn ($v) => $this->variantContext($v))->values()->toArray(),
            'attributes' => $product->attributeValues->map(fn ($av) => [
                'uuid' => $av->getAttribute('uuid'),
                'name' => $av->getRelation('definition')?->getAttribute('name') ?? null,
                'value' => $av->getAttribute('value'),
            ])->values()->toArray(),
            'media' => $product->productMedia->map(fn ($m) => [
                'uuid' => $m->getAttribute('uuid'),
                'mime_type' => $m->getAttribute('mime_type'),
            ])->values()->toArray(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function variantContext(?ProductVariant $variant): ?array
    {
        if ($variant === null) {
            return null;
        }

        $variant->loadMissing('attributeValues.definition');

        return [
            'uuid' => $variant->getAttribute('uuid'),
            'name' => $variant->getAttribute('name'),
            'sku' => $variant->getAttribute('sku'),
            'gtin' => $variant->getAttribute('gtin'),
            'mpn' => $variant->getAttribute('mpn'),
        ];
    }
}
