<?php

namespace App\Data\Passports;

class ValidatedDppPayload
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  string[]  $enabledSections
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $translations
     * @param  array<int, array{document_uuid: string, role: string, display_order: int}>  $documentReferences
     */
    public function __construct(
        public readonly array $payload,
        public readonly array $enabledSections,
        public readonly array $data,
        public readonly array $translations,
        public readonly array $documentReferences,
    ) {}
}
