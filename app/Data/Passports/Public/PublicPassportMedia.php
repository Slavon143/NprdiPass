<?php

namespace App\Data\Passports\Public;

readonly class PublicPassportMedia
{
    public function __construct(
        public string $mediaUuid,
        public string $originalFilename,
        public string $mimeType,
        public ?string $altText,
        public ?string $caption,
        public ?int $width,
        public ?int $height,
        public int $sortOrder,
    ) {}
}
