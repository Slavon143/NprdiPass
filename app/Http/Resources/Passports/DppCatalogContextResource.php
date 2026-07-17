<?php

namespace App\Http\Resources\Passports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DppCatalogContextResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'product_uuid' => $this->resource['product_uuid'] ?? null,
            'product_name' => $this->resource['product_name'] ?? null,
            'brand' => $this->resource['brand'] ?? null,
            'manufacturer' => $this->resource['manufacturer'] ?? null,
            'status' => $this->resource['status'] ?? null,
            'categories' => $this->resource['categories'] ?? [],
            'default_variant' => $this->resource['default_variant'] ?? null,
            'variants' => $this->resource['variants'] ?? [],
            'attributes' => $this->resource['attributes'] ?? [],
            'media' => $this->resource['media'] ?? [],
        ];
    }
}
