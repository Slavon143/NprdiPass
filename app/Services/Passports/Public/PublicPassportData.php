<?php

namespace App\Services\Passports\Public;

use App\Data\Passports\Public\PublicPassportDocument;
use App\Data\Passports\Public\PublicPassportMedia;
use App\Data\Passports\Public\PublicPassportViewModel;
use App\Services\Passports\Qr\PassportQrPayloadFactory;

class PublicPassportData
{
    /** @return array<string, mixed> */
    public function serialize(PublicPassportViewModel $passport): array
    {
        // The same allowlisted public view model feeds HTML and JSON. Never
        // serialize the underlying draft, snapshot, tenant row or readiness.
        $base = app(PassportQrPayloadFactory::class)->create($passport->passportPublicId);

        return [
            'format' => 'nordipass-public-passport-v1',
            'passport_uri' => $base,
            'version' => $passport->versionNumber,
            'published_at' => $passport->publishedAt,
            'language' => $passport->requestedLocale,
            'default_language' => $passport->defaultLanguage,
            'available_languages' => $passport->enabledLocales,
            'product' => [
                'name' => $passport->productName, 'brand' => $passport->productBrand,
                'manufacturer' => $passport->productManufacturer,
                'identifiers' => ['sku' => $passport->defaultVariantSku, 'gtin' => $passport->defaultVariantGtin, 'mpn' => $passport->defaultVariantMpn],
            ],
            'sections' => $passport->sectionData,
            'media' => array_map(fn (PublicPassportMedia $media): array => [
                'url' => $base.'/media/'.$media->mediaUuid, 'mime_type' => $media->mimeType,
                'alt_text' => $media->altText, 'caption' => $media->caption,
            ], $passport->media),
            'documents' => array_map(fn (PublicPassportDocument $document): array => [
                'url' => $base.'/documents/'.$document->assetUuid, 'title' => $document->title,
                'type' => $document->documentType, 'language' => $document->language,
                'issuer' => $document->issuerName, 'issue_date' => $document->issueDate,
                'expires_at' => $document->expiresAt, 'mime_type' => $document->mimeType,
            ], $passport->documents),
        ];
    }
}
