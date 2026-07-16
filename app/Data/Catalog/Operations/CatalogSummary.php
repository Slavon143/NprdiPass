<?php

namespace App\Data\Catalog\Operations;

readonly class CatalogSummary
{
    public function __construct(
        public string $companyUuid,
        public string $companyName,
        public int $categoriesCount = 0,
        public int $activeProducts = 0,
        public int $draftProducts = 0,
        public int $archivedProducts = 0,
        public int $activeVariants = 0,
        public int $draftVariants = 0,
        public int $archivedVariants = 0,
        public int $productsMissingPrimaryCategory = 0,
        public int $productsMissingDefaultVariant = 0,
        public int $productsMissingPrimaryMedia = 0,
        public int $productsNotReady = 0,
        public int $attributeDefinitionsCount = 0,
        public int $attributeOptionsCount = 0,
        public int $mediaCount = 0,
        public int $missingPhysicalFiles = 0,
        public int $staleDraftsCount = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'company_uuid' => $this->companyUuid,
            'company_name' => $this->companyName,
            'categories_count' => $this->categoriesCount,
            'active_products' => $this->activeProducts,
            'draft_products' => $this->draftProducts,
            'archived_products' => $this->archivedProducts,
            'active_variants' => $this->activeVariants,
            'draft_variants' => $this->draftVariants,
            'archived_variants' => $this->archivedVariants,
            'products_missing_primary_category' => $this->productsMissingPrimaryCategory,
            'products_missing_default_variant' => $this->productsMissingDefaultVariant,
            'products_missing_primary_media' => $this->productsMissingPrimaryMedia,
            'products_not_ready' => $this->productsNotReady,
            'attribute_definitions_count' => $this->attributeDefinitionsCount,
            'attribute_options_count' => $this->attributeOptionsCount,
            'media_count' => $this->mediaCount,
            'missing_physical_files' => $this->missingPhysicalFiles,
            'stale_drafts_count' => $this->staleDraftsCount,
        ];
    }
}
