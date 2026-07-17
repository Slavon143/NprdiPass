<?php

namespace App\Services\Passports\Publication;

use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Services\Passports\DppPayloadNormalizer;
use Carbon\CarbonImmutable;

class PassportSnapshotBuilder
{
    private DppPayloadNormalizer $normalizer;

    public function __construct(DppPayloadNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(
        ProductPassport $passport,
        ProductPassportVersion $draft,
        Company $company,
        string $versionUuid,
        int $versionNumber,
        string $publishedByUuid,
        PassportReadinessResult $readiness,
    ): array {
        $passport->loadMissing('product');
        /** @var Product $product */
        $product = $passport->product;

        $product->loadMissing([
            'primaryCategory',
            'defaultVariant',
            'variants',
            'productMedia' => function ($query) {
                $query->ordered();
            },
        ]);

        $normalizedPayload = $this->normalizer->normalize($draft->payload);

        $resolvedDocuments = $this->resolveDocuments($normalizedPayload, $company);

        return [
            'snapshot_schema_version' => 1,
            'passport' => [
                'uuid' => $passport->uuid,
                'public_id' => $passport->public_id,
                'default_language' => $passport->default_language,
                'source_draft_uuid' => $draft->uuid,
                'source_draft_revision' => $draft->draft_revision,
                'dpp_schema_version' => $draft->schema_version,
            ],
            'publication' => [
                'version_uuid' => $versionUuid,
                'version_number' => $versionNumber,
                'published_at' => (new CarbonImmutable)->toIso8601ZuluString(),
                'published_by_uuid' => $publishedByUuid,
                'readiness_score' => $readiness->score,
                'readiness_status' => $readiness->status->value,
                'warning_count' => $readiness->counts->warnings,
                'snapshot_schema_version' => 1,
            ],
            'product' => $this->buildProductSection($product),
            'variants' => $this->buildVariants($product),
            'dpp' => $normalizedPayload,
            'documents' => $this->buildDocumentEntries($resolvedDocuments),
            'media' => $this->buildMedia($product),
            'asset_manifest' => $this->buildAssetManifest($product, $resolvedDocuments),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProductSection(Product $product): array
    {
        return [
            'uuid' => $product->uuid,
            'name' => $product->name,
            'brand' => $product->brand,
            'manufacturer' => $product->manufacturer,
            'primary_category_name' => $product->primaryCategory?->name,
            'status' => $product->status->value,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildVariants(Product $product): array
    {
        $variants = [];

        foreach ($product->variants as $variant) {
            /** @var ProductVariant $variant */
            $variants[] = [
                'uuid' => $variant->uuid,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'gtin' => $variant->gtin,
                'mpn' => $variant->mpn,
                'status' => $variant->status->value,
                'sort_order' => $variant->sort_order,
            ];
        }

        return $variants;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMedia(Product $product): array
    {
        $media = [];

        foreach ($product->productMedia as $item) {
            /** @var ProductMedia $item */
            $media[] = [
                'uuid' => $item->uuid,
                'original_filename' => $item->original_filename,
                'mime_type' => $item->mime_type,
                'file_extension' => $this->extractExtension($item->original_filename),
                'size_bytes' => $item->size_bytes,
                'width' => $item->width,
                'height' => $item->height,
                'checksum_sha256' => $item->checksum_sha256,
                'alt_text' => $item->alt_text,
                'caption' => $item->caption,
                'sort_order' => $item->sort_order,
            ];
        }

        return $media;
    }

    /**
     * Resolve document UUIDs from the payload into loaded ProductDocumentVersion objects.
     *
     * @param  array<string, mixed>  $normalizedPayload
     * @return array<int, array{ref: array<string, mixed>, version: ProductDocumentVersion}>
     */
    private function resolveDocuments(array $normalizedPayload, Company $company): array
    {
        $resolved = [];

        $refs = $normalizedPayload['document_references'] ?? [];

        if ($refs === []) {
            return $resolved;
        }

        $documentUuids = array_values(array_unique(array_map(
            fn (array $ref): string => $ref['document_uuid'],
            $refs,
        )));

        $documents = ProductDocument::query()
            ->forCompany($company)
            ->with('currentVersion')
            ->whereIn('uuid', $documentUuids)
            ->get()
            ->keyBy('uuid');

        foreach ($refs as $ref) {
            $uuid = $ref['document_uuid'];
            /** @var ProductDocument|null $doc */
            $doc = $documents[$uuid] ?? null;

            if ($doc === null) {
                continue;
            }

            /** @var ProductDocumentVersion|null $version */
            $version = $doc->currentVersion;

            if ($version === null) {
                continue;
            }

            $resolved[] = [
                'ref' => $ref,
                'version' => $version,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<int, array{ref: array<string, mixed>, version: ProductDocumentVersion}>  $resolvedDocuments
     * @return array<int, array<string, mixed>>
     */
    private function buildDocumentEntries(array $resolvedDocuments): array
    {
        $entries = [];

        foreach ($resolvedDocuments as $resolved) {
            $ref = $resolved['ref'];
            $version = $resolved['version'];

            $entries[] = [
                'document_uuid' => $version->document->uuid,
                'version_uuid' => $version->uuid,
                'version_number' => $version->version_number,
                'document_type' => $version->document_type->value,
                'title' => $version->title,
                'description' => $version->description,
                'language' => $version->language,
                'visibility' => $version->visibility->value,
                'issuer_name' => $version->issuer_name,
                'issue_date' => $version->issue_date?->toDateString(),
                'expires_at' => $version->expires_at?->toDateString(),
                'original_filename' => $version->original_filename,
                'mime_type' => $version->mime_type,
                'file_extension' => $version->file_extension,
                'size_bytes' => $version->size_bytes,
                'checksum_sha256' => $version->checksum_sha256,
                'role' => $ref['role'],
                'display_order' => $ref['display_order'],
            ];
        }

        return $entries;
    }

    /**
     * @param  array<int, array{ref: array<string, mixed>, version: ProductDocumentVersion}>  $resolvedDocuments
     * @return array<int, array<string, mixed>>
     */
    private function buildAssetManifest(Product $product, array $resolvedDocuments): array
    {
        $manifest = [];

        foreach ($product->productMedia as $item) {
            /** @var ProductMedia $item */
            $manifest[] = [
                'asset_uuid' => $item->uuid,
                'source_disk' => 'catalog_media',
                'source_storage_key' => $item->storage_path,
                'file_extension' => $this->extractExtension($item->original_filename),
                'checksum_sha256' => $item->checksum_sha256,
            ];
        }

        foreach ($resolvedDocuments as $resolved) {
            $version = $resolved['version'];

            $manifest[] = [
                'asset_uuid' => $version->uuid,
                'source_disk' => 'product_documents',
                'source_storage_key' => $version->storage_key,
                'file_extension' => $version->file_extension,
                'checksum_sha256' => $version->checksum_sha256,
            ];
        }

        return $manifest;
    }

    private function extractExtension(string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        return mb_strtolower($ext);
    }
}
