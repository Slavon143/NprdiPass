<?php

namespace App\Services\Passports;

use App\Models\Catalog\ProductDocument;

class PassportSnapshotBuilder
{
    public function __construct(
        private readonly DppPayloadNormalizer $normalizer,
    ) {}

    public function build(array $draftPayload): array
    {
        $normalized = $this->normalizer->normalize($draftPayload);

        if (! empty($normalized['document_references'])) {
            foreach ($normalized['document_references'] as &$ref) {
                if (empty($ref['document_version_uuid']) && ! empty($ref['document_uuid'])) {
                    $document = ProductDocument::query()
                        ->where('uuid', $ref['document_uuid'])
                        ->first();

                    if ($document !== null && $document->currentVersion !== null) {
                        $ref['document_version_uuid'] = $document->currentVersion->uuid;
                    }
                }
            }
            unset($ref);
        }

        return $normalized;
    }
}
