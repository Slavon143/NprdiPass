<?php

namespace App\Services\Catalog\Documents;

readonly class ValidatedPdf
{
    public function __construct(
        public string $temporaryPath,
        public string $originalFilename,
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public string $checksum,
    ) {}
}
