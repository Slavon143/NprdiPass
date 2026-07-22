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
            'review_status' => $this->review_status->value,
            'approval_status' => $this->approval_status->value,
            'expiry_state' => $this->expiryState()->value,
            'metadata' => $this->metadata,
            'issuer_name' => $this->issuer_name,
            'certificate_number' => $this->certificate_number,
            'issuing_body' => $this->issuing_body,
            'declaration_identifier' => $this->declaration_identifier,
            'evidence_type' => $this->evidence_type,
            'topic_code' => $this->topic_code,
            'standard_reference' => $this->standard_reference,
            'applicable_market' => $this->applicable_market,
            'reference_url' => $this->reference_url,
            'issue_date' => $this->issue_date?->toISOString(),
            'valid_from' => $this->valid_from?->toISOString(),
            'valid_until' => $this->valid_until?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'original_filename' => $this->original_filename,
            'safe_display_filename' => $this->safe_display_filename,
            'mime_type' => $this->mime_type,
            'file_extension' => $this->file_extension,
            'size_bytes' => $this->size_bytes,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
