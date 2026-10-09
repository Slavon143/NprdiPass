<?php

namespace App\Services\Catalog\Operations;

use App\Data\Catalog\Operations\MediaCleanupReport;
use App\Models\Catalog\ProductMedia;
use App\Models\Company;
use App\Services\Catalog\Media\CatalogMediaStorage;
use App\Services\Catalog\Media\MediaStorageFence;
use App\Services\Storage\TenantStorageLedger;
use App\Support\Catalog\Media\MediaPathGuard;
use Generator;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Throwable;

class CatalogMediaCleanupService
{
    public function __construct(
        private readonly CatalogMediaStorage $mediaStorage,
        private readonly CatalogMediaOrphanScanner $orphanScanner,
        private readonly MediaPathGuard $pathGuard,
        private readonly MediaStorageFence $fence,
        private readonly TenantStorageLedger $ledger,
    ) {}

    public function cleanup(Company $company, bool $dryRun = true, int $olderThanHours = 24, int $limit = 500, bool $includeTrashed = false): MediaCleanupReport
    {
        $lease = $dryRun ? null : $this->fence->cleanup($this->mediaStorage->diskName());
        $scanned = $deleted = $skipped = $failed = $bytesReclaimed = 0;
        $failureReasons = [];
        $disk = $this->mediaStorage->disk();
        try {
            foreach ($this->candidates($company, $olderThanHours, $includeTrashed) as [$path, $trashed]) {
                if ($scanned >= $limit) {
                    break;
                }
                $scanned++;
                try {
                    $this->pathGuard->assertSafeRelative($path);
                    if (! str_starts_with($path, $company->uuid.'/')) {
                        $skipped++;

                        continue;
                    }
                    if ($dryRun) {
                        $skipped++;

                        continue;
                    }
                    $removed = DB::transaction(function () use ($company, $path, $trashed, $disk, $olderThanHours): ?int {
                        Company::withTrashed()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                        // Cross-tenant corrupt references also conservatively retain bytes.
                        $references = ProductMedia::withTrashed()->where('storage_path', $path);
                        if (! $trashed && (clone $references)->lockForUpdate()->exists()) {
                            return null;
                        }
                        if ($trashed) {
                            $eligible = (clone $references)->where('company_id', $company->id)
                                ->whereNotNull('deleted_at')->where('deleted_at', '<=', now()->subHours($olderThanHours))
                                ->lockForUpdate()->exists();
                            $unsafe = (clone $references)->where(function ($query) use ($company, $olderThanHours): void {
                                $query->whereNull('deleted_at')->orWhere('company_id', '!=', $company->id)
                                    ->orWhere('deleted_at', '>', now()->subHours($olderThanHours));
                            })->lockForUpdate()->exists();
                            if (! $eligible || $unsafe) {
                                return null;
                            }
                        }
                        if ($this->ledger->hasUnresolvedWrite($company, $this->mediaStorage->diskName(), $path, $trashed)) {
                            return null;
                        }
                        if (! $disk->exists($path) || $disk->lastModified($path) > now()->subHours($olderThanHours)->timestamp) {
                            return null;
                        }
                        $this->pathGuard->assertExistingLocalContainment($disk->path(''), $disk->path($path));
                        $bytes = $disk->size($path);
                        if (! $this->removeConfirmed($disk, $path)) {
                            throw new \RuntimeException('Physical deletion was not confirmed.');
                        }
                        $this->ledger->releaseRemovedKey($company, $this->mediaStorage->diskName(), $path);

                        return $bytes;
                    });
                    if ($removed === null) {
                        $skipped++;
                    } else {
                        $deleted++;
                        $bytesReclaimed += $removed;
                    }
                } catch (Throwable) {
                    $failed++;
                    $failureReasons[] = 'Media cleanup requires reconciliation.';
                }
            }
        } finally {
            $lease?->release();
        }

        return new MediaCleanupReport(
            scanned: $scanned, candidates: $scanned, deleted: $deleted, skipped: $skipped,
            failed: $failed, bytesReclaimed: $bytesReclaimed, failureReasons: $failureReasons,
        );
    }

    private function removeConfirmed(FilesystemAdapter $disk, string $path): bool
    {
        return $disk->delete($path) && ! $disk->exists($path);
    }

    /** @return Generator<int, array{string, bool}> */
    private function candidates(Company $company, int $hours, bool $includeTrashed): Generator
    {
        foreach ($this->orphanScanner->iterateOrphans($company, $hours) as $path) {
            yield [$path, false];
        }
        if ($includeTrashed) {
            foreach (ProductMedia::onlyTrashed()->forCompany($company)->where('deleted_at', '<=', now()->subHours($hours))->lazyById(500) as $media) {
                yield [$media->storage_path, true];
            }
        }
    }
}
