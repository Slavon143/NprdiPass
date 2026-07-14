<?php

namespace App\Console\Commands\Catalog;

use App\Models\Catalog\ProductMedia;
use App\Services\Catalog\Media\CatalogMediaStorage;
use App\Support\Catalog\Media\MediaPathGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneOrphanMediaCommand extends Command
{
    protected $signature = 'catalog:prune-orphan-media {--delete : Delete eligible files} {--older-than=24 : Minimum file age in hours} {--limit=500 : Maximum files to inspect for deletion}';

    protected $description = 'Report or delete old orphan catalog images and retry files for soft-deleted media.';

    public function handle(CatalogMediaStorage $storage, MediaPathGuard $pathGuard): int
    {
        $delete = (bool) $this->option('delete');
        $hours = filter_var($this->option('older-than'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($hours === false || $hours < 0 || $limit === false || $limit < 1 || $limit > 10000) {
            $this->error('Invalid cleanup options.');

            return self::INVALID;
        }
        if ($delete && app()->environment('testing') && ! $this->safeTestingRoot($storage)) {
            $this->error('Refusing deletion outside the isolated test media root.');

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);
        $known = ProductMedia::withTrashed()->pluck('storage_path')
            ->filter(fn (mixed $value): bool => is_string($value))->flip();
        $candidates = [];

        foreach ($storage->disk()->allFiles() as $path) {
            if (count($candidates) >= $limit) {
                break;
            }
            try {
                $path = $pathGuard->assertSafeRelative($path);
            } catch (Throwable) {
                $this->warn('Skipped an unsafe media path.');

                continue;
            }
            if ($known->has($path)) {
                continue;
            }
            try {
                if ($storage->disk()->lastModified($path) > $cutoff->timestamp) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }
            $candidates[$path] = 'orphan';
        }

        $remaining = $limit - count($candidates);
        if ($remaining > 0) {
            ProductMedia::onlyTrashed()->where('deleted_at', '<=', $cutoff)->orderBy('id')->limit($remaining)->get()->each(function (ProductMedia $media) use (&$candidates, $storage, $pathGuard): void {
                try {
                    $path = $pathGuard->assertSafeRelative($media->storage_path);
                    if ($storage->exists($path)) {
                        $candidates[$path] = 'soft-deleted';
                    }
                } catch (Throwable) { /* unsafe persisted paths are reported, never deleted */
                }
            });
        }

        $deleted = 0;
        $failed = 0;
        if ($delete) {
            foreach ($candidates as $path => $kind) {
                try {
                    if ($storage->delete($path)) {
                        $deleted++;
                    } else {
                        $failed++;
                    }
                } catch (Throwable) {
                    $failed++;
                    Log::warning('Catalog media cleanup deletion failed.', ['operation' => 'cleanup', 'error_code' => 'delete_failed', 'candidate_type' => $kind]);
                }
            }
        }
        $mode = $delete ? 'delete' : 'dry-run';
        $this->info("Catalog media cleanup {$mode}: candidates=".count($candidates).", deleted={$deleted}, failed={$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function safeTestingRoot(CatalogMediaStorage $storage): bool
    {
        $root = $storage->disk()->path('');
        $marker = (string) config('catalog.media.test_root_marker');

        return $root !== '' && $marker !== '' && str_contains(str_replace('\\', '/', $root), $marker)
            && realpath($root) !== realpath(storage_path('app/catalog-media'));
    }
}
