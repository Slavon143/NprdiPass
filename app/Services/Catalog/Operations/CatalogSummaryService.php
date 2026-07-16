<?php

namespace App\Services\Catalog\Operations;

use App\Data\Catalog\Operations\CatalogSummary;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Services\Catalog\Media\CatalogMediaStorage;
use Illuminate\Support\Facades\DB;
use Throwable;

class CatalogSummaryService
{
    public function __construct(
        private readonly CatalogMediaStorage $mediaStorage,
    ) {}

    public function summarize(Company $company, bool $verifyFiles = false): CatalogSummary
    {
        $companyId = $company->getKey();

        $categoriesCount = Category::query()
            ->forCompany($company)
            ->where('status', CategoryStatus::Active->value)
            ->whereNull('deleted_at')
            ->count();

        $productStatusCounts = Product::query()
            ->forCompany($company)
            ->whereNull('deleted_at')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $variantStatusCounts = ProductVariant::query()
            ->forCompany($company)
            ->whereNull('deleted_at')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $productsMissingPrimaryCategory = Product::query()
            ->forCompany($company)
            ->whereNull('primary_category_id')
            ->whereNull('deleted_at')
            ->count();

        $productsMissingDefaultVariant = Product::query()
            ->forCompany($company)
            ->whereNull('default_variant_id')
            ->whereNull('deleted_at')
            ->count();

        $productsMissingPrimaryMedia = Product::query()
            ->forCompany($company)
            ->whereNull('primary_media_id')
            ->whereNull('deleted_at')
            ->count();

        $productsNotReady = Product::query()
            ->forCompany($company)
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->whereNull('name')
                    ->orWhere('name', '')
                    ->orWhereNull('primary_category_id')
                    ->orWhereNull('default_variant_id');
            })
            ->count();

        $attributeDefinitionsCount = AttributeDefinition::query()
            ->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->count();

        $attributeOptionsCount = AttributeOption::query()
            ->forCompany($company)
            ->where('status', AttributeOptionStatus::Active->value)
            ->count();

        $mediaCount = ProductMedia::query()
            ->forCompany($company)
            ->whereNull('deleted_at')
            ->count();

        $missingPhysicalFiles = 0;

        if ($verifyFiles) {
            $mediaRows = ProductMedia::query()
                ->forCompany($company)
                ->whereNull('deleted_at')
                ->whereNotNull('storage_path')
                ->where('storage_path', '!=', '')
                ->select('storage_path')
                ->get();

            foreach ($mediaRows as $row) {
                if (! $this->fileExists($row->storage_path)) {
                    $missingPhysicalFiles++;
                }
            }
        }

        $staleDraftDays = (int) config('catalog.operations.stale_draft_days', 90);

        $staleDraftsCount = Product::query()
            ->forCompany($company)
            ->where('status', ProductStatus::Draft->value)
            ->whereNull('deleted_at')
            ->where('updated_at', '<', now()->subDays($staleDraftDays))
            ->count();

        return new CatalogSummary(
            companyUuid: $company->uuid,
            companyName: $company->name,
            categoriesCount: $categoriesCount,
            activeProducts: (int) ($productStatusCounts[ProductStatus::Active->value] ?? 0),
            draftProducts: (int) ($productStatusCounts[ProductStatus::Draft->value] ?? 0),
            archivedProducts: (int) ($productStatusCounts[ProductStatus::Archived->value] ?? 0),
            activeVariants: (int) ($variantStatusCounts[ProductVariantStatus::Active->value] ?? 0),
            draftVariants: (int) ($variantStatusCounts[ProductVariantStatus::Draft->value] ?? 0),
            archivedVariants: (int) ($variantStatusCounts[ProductVariantStatus::Archived->value] ?? 0),
            productsMissingPrimaryCategory: $productsMissingPrimaryCategory,
            productsMissingDefaultVariant: $productsMissingDefaultVariant,
            productsMissingPrimaryMedia: $productsMissingPrimaryMedia,
            productsNotReady: $productsNotReady,
            attributeDefinitionsCount: $attributeDefinitionsCount,
            attributeOptionsCount: $attributeOptionsCount,
            mediaCount: $mediaCount,
            missingPhysicalFiles: $missingPhysicalFiles,
            staleDraftsCount: $staleDraftsCount,
        );
    }

    private function fileExists(string $path): bool
    {
        try {
            return $this->mediaStorage->exists($path);
        } catch (Throwable) {
            return false;
        }
    }
}
