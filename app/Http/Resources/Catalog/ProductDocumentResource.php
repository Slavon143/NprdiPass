<?php

namespace App\Http\Resources\Catalog;

use App\Models\Catalog\ProductDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductDocument */
class ProductDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'product_uuid' => $this->when($this->relationLoaded('product'), fn () => $this->product->uuid),
            'current_version' => ProductDocumentVersionResource::make($this->whenLoaded('currentVersion')),
            'version_count' => $this->whenCounted('versions'),
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
