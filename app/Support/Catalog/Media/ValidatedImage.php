<?php

namespace App\Support\Catalog\Media;

final readonly class ValidatedImage
{
    public function __construct(
        public string $temporaryPath,
        public string $originalFilename,
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public int $width,
        public int $height,
        public string $checksumSha256,
    ) {}
}
