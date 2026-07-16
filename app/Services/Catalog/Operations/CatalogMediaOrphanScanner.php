<?php

namespace App\Services\Catalog\Operations;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Company;
use App\Services\Catalog\Media\CatalogMediaStorage;
use Throwable;

class CatalogMediaOrphanScanner
{
    public function __construct(
        private readonly CatalogMediaStorage $mediaStorage,
    ) {}

    /**
     * @return string[]
     */
    public function scanOrphans(Company $company, int $olderThanHours = 24): array
    {
        $companyPrefix = $company->uuid.'/';
        $cutoff = now()->subHours($olderThanHours);
        $disk = $this->mediaStorage->disk();

        $knownPaths = ProductMedia::withTrashed()
            ->select('storage_path')
            ->pluck('storage_path')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->toArray();

        $knownMap = array_flip($knownPaths);
        $orphans = [];

        $allFiles = $disk->allFiles();

        foreach ($allFiles as $path) {
            if (! str_starts_with($path, $companyPrefix)) {
                continue;
            }

            if (array_key_exists($path, $knownMap)) {
                continue;
            }

            try {
                $lastModified = $disk->lastModified($path);
                if ($lastModified > $cutoff->timestamp) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $orphans[] = $path;
        }

        return $orphans;
    }

    /**
     * @return array<int, array{uuid: string, storage_path: string, product_uuid: string, variant_uuid: string|null}>
     */
    public function scanMissingFiles(Company $company): array
    {
        $mediaRows = ProductMedia::query()
            ->forCompany($company)
            ->whereNull('deleted_at')
            ->whereNotNull('storage_path')
            ->where('storage_path', '!=', '')
            ->select('uuid', 'storage_path', 'product_id', 'product_variant_id')
            ->get();

        $missing = [];

        foreach ($mediaRows as $row) {
            if (! $this->fileExists($row->storage_path)) {
                $productUuid = Product::query()
                    ->whereKey($row->product_id)
                    ->value('uuid') ?? '';
                $variantUuid = '';

                $missing[] = [
                    'uuid' => $row->uuid,
                    'storage_path' => $row->storage_path,
                    'product_uuid' => $productUuid,
                    'variant_uuid' => $variantUuid,
                ];
            }
        }

        return $missing;
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
