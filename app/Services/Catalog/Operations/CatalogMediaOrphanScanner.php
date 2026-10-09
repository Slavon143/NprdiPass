<?php

namespace App\Services\Catalog\Operations;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Services\Catalog\Media\CatalogMediaStorage;
use Generator;
use League\Flysystem\FileAttributes;
use Throwable;

class CatalogMediaOrphanScanner
{
    public function __construct(
        private readonly CatalogMediaStorage $mediaStorage,
    ) {}

    /** @return string[] */
    public function scanOrphans(Company $company, int $olderThanHours = 24): array
    {
        return iterator_to_array($this->iterateOrphans($company, $olderThanHours), false);
    }

    /** @return Generator<int, string> */
    public function iterateOrphans(Company $company, int $olderThanHours = 24): Generator
    {
        $prefix = $company->uuid.'/';
        $cutoff = now()->subHours($olderThanHours)->timestamp;
        $disk = $this->mediaStorage->disk();
        $batch = [];

        foreach ($disk->getDriver()->listContents($prefix, true) as $entry) {
            if (! $entry instanceof FileAttributes || ! str_starts_with($entry->path(), $prefix)) {
                continue;
            }

            try {
                $modified = $entry->lastModified() ?? $disk->lastModified($entry->path());
            } catch (Throwable) {
                continue;
            }

            if ($modified > $cutoff) {
                continue;
            }

            $batch[] = $entry->path();
            if (count($batch) === 500) {
                yield from $this->unreferenced($company, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield from $this->unreferenced($company, $batch);
        }
    }

    /** @param list<string> $paths
     * @return Generator<int, string>
     */
    private function unreferenced(Company $company, array $paths): Generator
    {
        $known = ProductMedia::withTrashed()->forCompany($company)
            ->whereIn('storage_path', $paths)->pluck('storage_path')->all();
        $knownMap = array_fill_keys($known, true);
        foreach ($paths as $path) {
            if (! isset($knownMap[$path])) {
                yield $path;
            }
        }
    }

    /**
     * @return array<int, array{uuid: string, storage_path: string, product_uuid: string, variant_uuid: string|null}>
     */
    public function scanMissingFiles(Company $company): array
    {
        $mediaRows = ProductMedia::query()->forCompany($company)
            ->whereNotNull('storage_path')->where('storage_path', '!=', '')
            ->select('id', 'uuid', 'storage_path', 'product_id', 'product_variant_id')
            ->with([
                'product' => fn ($query) => $query->where('company_id', $company->id)->select('id', 'uuid'),
                'variant' => fn ($query) => $query->where('company_id', $company->id)->select('id', 'uuid', 'product_id'),
            ])->lazyById(500);
        $missing = [];

        foreach ($mediaRows as $row) {
            // Storage failures must not be presented as confirmed missing files.
            if ($this->mediaStorage->exists($row->storage_path)) {
                continue;
            }

            $product = $row->product;
            $variant = $row->variant;
            $missing[] = [
                'uuid' => $row->uuid,
                'storage_path' => $row->storage_path,
                'product_uuid' => $product instanceof Product ? $product->uuid : '',
                'variant_uuid' => $variant instanceof ProductVariant && $variant->product_id === $row->product_id ? $variant->uuid : null,
            ];
        }

        return $missing;
    }
}
