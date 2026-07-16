<?php

namespace App\Http\Resources\Catalog;

use App\Models\Catalog\ProductDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductDocument */
class ProductDocumentSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currentVersion = $this->relationLoaded('currentVersion') ? $this->currentVersion : null;

        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'product_uuid' => $this->when($this->relationLoaded('product'), fn () => $this->product->uuid),
            'title' => $currentVersion?->title,
            'document_type' => $currentVersion?->document_type?->value,
            'language' => $currentVersion?->language,
            'visibility' => $currentVersion?->visibility?->value,
            'expires_at' => $currentVersion?->expires_at?->toISOString(),
            'version_count' => $this->whenCounted('versions'),
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
