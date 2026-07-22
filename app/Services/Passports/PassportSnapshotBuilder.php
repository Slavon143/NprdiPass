<?php

namespace App\Services\Passports;

use App\Enums\Documents\ProductDocumentApprovalStatus;
use App\Enums\Documents\ProductDocumentReviewStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Services\Catalog\Documents\ProductDocumentCurrentVersionResolver;

class PassportSnapshotBuilder
{
    public function __construct(
        private readonly DppPayloadNormalizer $normalizer,
        private readonly ProductDocumentCurrentVersionResolver $documentVersionResolver,
    ) {}

    public function build(array $draftPayload, ?Product $product = null): array
    {
        $normalized = $this->normalizer->normalize($draftPayload);

        if ($product !== null && ! empty($normalized['document_references'])) {
            $normalized = $this->resolveDocumentReferencesForProduct($normalized, $product);
        }

        if ($product !== null) {
            $normalized['_catalog_context'] = $this->buildCatalogContext($product, $normalized);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function resolveDocumentReferencesForProduct(array $normalized, Product $product): array
    {
        foreach ($normalized['document_references'] as &$ref) {
            $documentUuid = $ref['document_uuid'] ?? null;

            if (! is_string($documentUuid) || $documentUuid === '') {
                unset($ref['document_version_uuid']);

                continue;
            }

            $document = ProductDocument::query()
                ->where('company_id', $product->company_id)
                ->where('product_id', $product->getKey())
                ->where('uuid', $documentUuid)
                ->active()
                ->with('currentVersion')
                ->first();

            if ($document === null) {
                unset($ref['document_version_uuid']);

                continue;
            }

            $versionUuid = $ref['document_version_uuid'] ?? null;

            if (! is_string($versionUuid) || $versionUuid === '') {
                $resolvedVersion = $this->documentVersionResolver->resolve($document, true);
                if ($resolvedVersion !== null) {
                    $ref['document_version_uuid'] = $resolvedVersion->uuid;
                }

                continue;
            }

            $versionBelongsToDocument = ProductDocumentVersion::query()
                ->where('company_id', $product->company_id)
                ->where('document_id', $document->getKey())
                ->where('uuid', $versionUuid)
                ->where('visibility', ProductDocumentVisibility::PassportPublic->value)
                ->where('review_status', ProductDocumentReviewStatus::Approved->value)
                ->where('approval_status', ProductDocumentApprovalStatus::Approved->value)
                ->where('file_available', true)
                ->exists();

            if (! $versionBelongsToDocument) {
                unset($ref['document_version_uuid']);
            }
        }
        unset($ref);

        return $normalized;
    }

    private function buildCatalogContext(Product $product, array $normalized): array
    {
        $product->loadMissing([
            'primaryCategory',
            'defaultVariant',
            'variants',
            'productMedia' => function ($query) {
                $query->ordered();
            },
        ]);

        $documents = [];
        $docRefs = $normalized['document_references'] ?? [];

        if (! empty($docRefs)) {
            $versionUuids = array_values(array_unique(array_filter(
                array_map(fn (array $ref): ?string => $ref['document_version_uuid'] ?? null, $docRefs),
            )));

            if (! empty($versionUuids)) {
                $versions = ProductDocumentVersion::query()
                    ->where('company_id', $product->company_id)
                    ->whereIn('uuid', $versionUuids)
                    ->whereHas('document', function ($query) use ($product): void {
                        $query
                            ->where('company_id', $product->company_id)
                            ->where('product_id', $product->getKey())
                            ->where('status', ProductDocumentStatus::Active->value);
                    })
                    ->with('document')
                    ->get()
                    ->keyBy('uuid');

                foreach ($docRefs as $ref) {
                    $versionUuid = $ref['document_version_uuid'] ?? null;
                    if ($versionUuid === null) {
                        continue;
                    }

                    $version = $versions[$versionUuid] ?? null;
                    if ($version === null) {
                        continue;
                    }

                    $documents[] = [
                        'document_uuid' => $version->document->uuid,
                        'version_uuid' => $version->uuid,
                        'asset_uuid' => $version->uuid,
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
                        'storage_key' => $version->storage_key,
                        'role' => $ref['role'] ?? 'other',
                        'display_order' => $ref['display_order'] ?? 0,
                    ];
                }
            }
        }

        $mediaItems = [];
        $productMediaItems = $product->productMedia->all();
        foreach ($productMediaItems as $mediaItem) {
            if (! $mediaItem instanceof ProductMedia) {
                continue;
            }
            $ext = mb_strtolower(pathinfo($mediaItem->original_filename, PATHINFO_EXTENSION));

            $mediaItems[] = [
                'uuid' => $mediaItem->uuid,
                'asset_uuid' => $mediaItem->uuid,
                'original_filename' => $mediaItem->original_filename,
                'mime_type' => $mediaItem->mime_type,
                'file_extension' => $ext,
                'size_bytes' => $mediaItem->size_bytes,
                'width' => $mediaItem->width,
                'height' => $mediaItem->height,
                'checksum_sha256' => $mediaItem->checksum_sha256,
                'alt_text' => $mediaItem->alt_text,
                'caption' => $mediaItem->caption,
                'sort_order' => $mediaItem->sort_order,
                'storage_path' => $mediaItem->storage_path,
            ];
        }

        return [
            'product' => [
                'uuid' => $product->uuid,
                'name' => $product->name,
                'brand' => $product->brand,
                'manufacturer' => $product->manufacturer,
                'primary_category_name' => $product->primaryCategory?->name,
                'status' => $product->status->value,
            ],
            'default_variant' => $product->defaultVariant ? [
                'uuid' => $product->defaultVariant->uuid,
                'name' => $product->defaultVariant->name,
                'sku' => $product->defaultVariant->sku,
                'gtin' => $product->defaultVariant->gtin,
                'mpn' => $product->defaultVariant->mpn,
            ] : null,
            'variants' => $product->variants->map(fn (ProductVariant $v) => [
                'uuid' => $v->uuid,
                'name' => $v->name,
                'sku' => $v->sku,
                'gtin' => $v->gtin,
                'mpn' => $v->mpn,
                'status' => $v->status->value,
                'sort_order' => $v->sort_order,
            ])->values()->toArray(),
            'media' => $mediaItems,
            'documents' => $documents,
        ];
    }
}
