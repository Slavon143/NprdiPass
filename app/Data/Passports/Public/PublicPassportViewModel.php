<?php

namespace App\Data\Passports\Public;

readonly class PublicPassportViewModel
{
    /** @param  string[]  $enabledSections */
    /** @param  array<string, array<string, mixed>>  $sectionData */
    /** @param  array<string, string>  $sectionLabels */
    /** @param  PublicPassportMedia[]  $media */
    /** @param  PublicPassportDocument[]  $documents */
    public function __construct(
        public string $passportPublicId,
        public int $versionNumber,
        public string $publishedAt,
        public string $defaultLanguage,
        public string $snapshotChecksum,
        public string $productName,
        public ?string $productBrand,
        public ?string $productManufacturer,
        public ?string $productCategory,
        public ?string $defaultVariantSku,
        public ?string $defaultVariantGtin,
        public ?string $defaultVariantMpn,
        public array $enabledSections,
        public array $sectionData,
        public array $sectionLabels,
        public array $media,
        public array $documents,
        public string $pageTitle,
        public string $metaDescription,
        public string $canonicalUrl,
        public ?string $ogImageUrl,
        public string $jsonLd,
        public ?string $countryOfOrigin,
        public ?string $manufacturerDisplayName,
    ) {}
}
