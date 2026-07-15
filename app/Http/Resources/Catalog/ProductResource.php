<?php

namespace App\Http\Resources\Catalog;

use App\Models\Catalog\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'brand' => $this->brand,
            'manufacturer' => $this->manufacturer,
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toISOString(),
            'primary_category' => CategoryResource::make($this->whenLoaded('primaryCategory')),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'default_variant' => ProductVariantResource::make($this->whenLoaded('defaultVariant')),
            'variant_count' => $this->whenCounted('variants'),
            'primary_media' => ProductMediaResource::make($this->whenLoaded('primaryMedia')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
