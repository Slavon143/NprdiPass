<?php

namespace App\Queries\Passports;

use App\Models\Passports\ProductPassport;

class ProductPassportEditorQuery
{
    /**
     * @return array<string, mixed>
     */
    public function editorData(ProductPassport $passport): array
    {
        $passport->loadMissing([
            'currentDraftVersion',
            'product.documents' => function ($query) {
                $query->with('currentVersion')->active();
            },
        ]);

        $draft = $passport->currentDraftVersion;

        return [
            'passport_uuid' => $passport->getAttribute('uuid'),
            'public_id' => $passport->getAttribute('public_id'),
            'status' => $passport->status->value,
            'default_language' => $passport->getAttribute('default_language'),
            'enabled_languages' => $passport->getAttribute('enabled_languages'),
            'created_at' => $passport->getAttribute('created_at')?->toISOString(),
            'draft_version_uuid' => $draft?->getAttribute('uuid'),
            'draft_revision' => $draft?->getAttribute('draft_revision'),
            'schema_version' => $draft?->getAttribute('schema_version'),
            'payload' => $draft?->getAttribute('payload'),
        ];
    }
}
