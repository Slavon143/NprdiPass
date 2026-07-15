<?php

namespace App\Http\Resources\Catalog;

use App\Models\Catalog\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
class ProductVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isDefault = null;
        if ($this->relationLoaded('product')) {
            $isDefault = $this->product->default_variant_id === $this->id;
        }

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'sku' => $this->sku,
            'gtin' => $this->gtin,
            'mpn' => $this->mpn,
            'status' => $this->status->value,
            'sort_order' => $this->sort_order,
            'is_default_product' => $isDefault,
            'primary_media' => ProductMediaResource::make($this->whenLoaded('primaryMedia')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
