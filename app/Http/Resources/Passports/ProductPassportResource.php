<?php

namespace App\Http\Resources\Passports;

use App\Models\Passports\ProductPassport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductPassport */
class ProductPassportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $draft = $this->currentDraftVersion;

        return [
            'passport_uuid' => $this->uuid,
            'public_id' => $this->public_id,
            'status' => $this->status->value,
            'default_language' => $this->default_language,
            'enabled_languages' => $this->enabled_languages,
            'draft_version_uuid' => $draft?->uuid,
            'draft_revision' => $draft?->draft_revision,
            'schema_version' => $draft?->schema_version,
            'payload' => $draft?->payload,
            'catalog_context' => $this->when($this->resource instanceof ProductPassport && isset($this->additional['catalog_context']), $this->additional['catalog_context'] ?? null),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
