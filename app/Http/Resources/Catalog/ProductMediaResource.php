<?php

namespace App\Http\Resources\Catalog;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductMedia */
class ProductMediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'alt_text' => $this->alt_text,
            'caption' => $this->caption,
            'sort_order' => $this->sort_order,
            'is_primary' => $this->resolveIsPrimary(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function resolveIsPrimary(): ?bool
    {
        if ($this->relationLoaded('product')) {
            $product = $this->product;
            if ($product instanceof Product) {
                return $product->primary_media_id === $this->id;
            }
        }

        if ($this->relationLoaded('variant')) {
            $variant = $this->variant;
            if ($variant instanceof ProductVariant) {
                return $variant->primary_media_id === $this->id;
            }
        }

        return null;
    }
}
