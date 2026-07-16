<?php

namespace App\Services\Catalog\Operations;

use App\Data\Catalog\Operations\MediaCleanupReport;
use App\Models\Catalog\ProductMedia;
use App\Models\Company;
use App\Services\Catalog\Media\CatalogMediaStorage;
use App\Support\Catalog\Media\MediaPathGuard;
use Throwable;

class CatalogMediaCleanupService
{
    public function __construct(
        private readonly CatalogMediaStorage $mediaStorage,
        private readonly CatalogMediaOrphanScanner $orphanScanner,
        private readonly MediaPathGuard $pathGuard,
    ) {}

    public function cleanup(Company $company, bool $dryRun = true, int $olderThanHours = 24, int $limit = 500): MediaCleanupReport
    {
        $candidates = $this->orphanScanner->scanOrphans($company, $olderThanHours);
        $scanned = count($candidates);

        if (count($candidates) > $limit) {
            $candidates = array_slice($candidates, 0, $limit);
        }

        $knownPaths = ProductMedia::withTrashed()
            ->select('storage_path')
            ->pluck('storage_path')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->toArray();

        $knownMap = array_flip($knownPaths);
        $deleted = 0;
        $skipped = 0;
        $failed = 0;
        $bytesReclaimed = 0;
        $failureReasons = [];
        $disk = $this->mediaStorage->disk();

        foreach ($candidates as $path) {
            try {
                $this->pathGuard->assertSafeRelative($path);
            } catch (Throwable) {
                $skipped++;
                $failureReasons[] = sprintf('Unsafe path skipped: %s', $path);

                continue;
            }

            if (array_key_exists($path, $knownMap)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $skipped++;

                continue;
            }

            try {
                $fileSize = 0;
                if ($disk->exists($path)) {
                    $fileSize = $disk->size($path);
                }

                if ($disk->delete($path)) {
                    $deleted++;
                    $bytesReclaimed += $fileSize;
                } else {
                    $failed++;
                    $failureReasons[] = sprintf('Failed to delete file: %s', $path);
                }
            } catch (Throwable $e) {
                $failed++;
                $failureReasons[] = sprintf('Error deleting %s: %s', $path, $e->getMessage());
            }
        }

        return new MediaCleanupReport(
            scanned: $scanned,
            candidates: count($candidates),
            deleted: $deleted,
            skipped: $skipped,
            failed: $failed,
            bytesReclaimed: $bytesReclaimed,
            failureReasons: $failureReasons,
        );
    }
}
