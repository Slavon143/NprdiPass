<?php

namespace App\Console\Commands\Catalog;

use App\Models\Company;
use App\Services\Catalog\Media\CatalogMediaStorage;
use App\Services\Catalog\Operations\CatalogMediaCleanupService;
use Illuminate\Console\Command;
use Throwable;

class PruneOrphanMediaCommand extends Command
{
    protected $signature = 'catalog:prune-orphan-media {--delete : Delete eligible files} {--older-than=24 : Minimum file age in hours} {--limit=500 : Maximum files to inspect for deletion}';

    protected $description = 'Report or delete old tenant catalog images and retry files for soft-deleted media.';

    public function handle(CatalogMediaStorage $storage, CatalogMediaCleanupService $cleanup): int
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
        $candidates = $deleted = $failed = 0;
        try {
            foreach (Company::withTrashed()->lazyById(500) as $company) {
                if ($candidates >= $limit) {
                    break;
                }
                $report = $cleanup->cleanup($company, ! $delete, $hours, $limit - $candidates, true);
                $candidates += $report->candidates;
                $deleted += $report->deleted;
                $failed += $report->failed;
            }
        } catch (Throwable) {
            $this->error('Media cleanup could not acquire safe storage access; no further files were deleted.');

            return self::FAILURE;
        }
        $mode = $delete ? 'delete' : 'dry-run';
        $this->info("Catalog media cleanup {$mode}: candidates={$candidates}, deleted={$deleted}, failed={$failed}.");

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
