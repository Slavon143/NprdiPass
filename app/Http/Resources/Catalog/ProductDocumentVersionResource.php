<?php

namespace App\Http\Resources\Catalog;

use App\Models\Catalog\ProductDocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductDocumentVersion */
class ProductDocumentVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'version_number' => $this->version_number,
            'document_type' => $this->document_type->value,
            'title' => $this->title,
            'description' => $this->description,
            'language' => $this->language,
            'visibility' => $this->visibility->value,
            'issuer_name' => $this->issuer_name,
            'issue_date' => $this->issue_date?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_extension' => $this->file_extension,
            'size_bytes' => $this->size_bytes,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
