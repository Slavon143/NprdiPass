<?php

namespace App\Data\Passports\Qr;

readonly class PassportQrViewModel
{
    public function __construct(
        public string $publicId,
        public string $publicUrl,
        public bool $isPublished,
        public bool $hasBeenPublished,
        public ?int $versionNumber,
        public string $passportStatus,
        public string $productName,
        public string $productUuid,
    ) {}

    public function targetStatusLabel(): string
    {
        if ($this->isPublished) {
            if ($this->versionNumber !== null) {
                return "Published · Version {$this->versionNumber}";
            }

            return 'Published';
        }

        if ($this->passportStatus === 'Archived') {
            return 'Archived — target unavailable';
        }

        if ($this->hasBeenPublished) {
            return 'Unpublished — target currently unavailable';
        }

        return 'Draft — not published yet';
    }

    public function targetStatusCode(): string
    {
        return $this->isPublished ? '200' : '404';
    }

    public function safeProductName(): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $this->productName);
    }
}
