<?php

namespace App\Http\Resources\Catalog;

use App\Models\Catalog\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'brand' => $this->brand,
            'default_variant' => $this->whenLoaded('defaultVariant', fn (): array => [
                'uuid' => $this->defaultVariant->uuid,
                'name' => $this->defaultVariant->name,
                'sku' => $this->defaultVariant->sku,
                'status' => $this->defaultVariant->status->value,
            ]),
            'variant_count' => $this->whenCounted('variants'),
            'primary_category_uuid' => $this->whenLoaded('primaryCategory', fn (): string => $this->primaryCategory->uuid),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
