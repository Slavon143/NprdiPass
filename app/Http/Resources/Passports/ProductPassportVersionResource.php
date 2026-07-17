<?php

namespace App\Http\Resources\Passports;

use App\Models\Passports\ProductPassportVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductPassportVersion */
class ProductPassportVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'version_uuid' => $this->uuid,
            'version_number' => $this->version_number,
            'status' => $this->status->value,
            'draft_revision' => $this->draft_revision,
            'schema_version' => $this->schema_version,
            'content_checksum' => $this->when(
                $this->content_checksum !== null && $request->user() !== null,
                $this->content_checksum,
            ),
            'payload' => $this->payload,
            'published_at' => $this->published_at?->toISOString(),
            'published_by' => $this->whenLoaded('publisher', fn () => $this->publisher?->only(['uuid', 'name'])),
            'superseded_at' => $this->superseded_at?->toISOString(),
            'withdrawn_at' => $this->withdrawn_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
