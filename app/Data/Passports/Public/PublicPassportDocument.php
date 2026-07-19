<?php

namespace App\Data\Passports\Public;

readonly class PublicPassportDocument
{
    public function __construct(
        public string $assetUuid,
        public string $documentUuid,
        public string $title,
        public string $documentType,
        public string $language,
        public ?string $issuerName,
        public ?string $issueDate,
        public ?string $expiresAt,
        public string $fileExtension,
        public string $mimeType,
        public int $sizeBytes,
        public string $formattedSize,
        public int $displayOrder,
    ) {}
}
