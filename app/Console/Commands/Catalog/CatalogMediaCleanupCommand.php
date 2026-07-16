<?php

namespace App\Console\Commands\Catalog;

use App\Models\Company;
use App\Services\Catalog\Operations\CatalogMediaCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CatalogMediaCleanupCommand extends Command
{
    protected $signature = 'catalog:media-cleanup
        {--company= : Company UUID to scan}
        {--all-companies : Scan all companies}
        {--dry-run : Perform a dry run (default)}
        {--execute : Actually delete orphan files}
        {--older-than=24 : Minimum file age in hours}
        {--limit=1000 : Maximum files to inspect}
        {--format=table : Output format (table or json)}';

    protected $description = 'Scan and clean up orphaned catalog media files';

    public function handle(CatalogMediaCleanupService $cleanupService): int
    {
        $companyUuid = $this->option('company');
        $allCompanies = (bool) $this->option('all-companies');
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if (($companyUuid === null || $companyUuid === '') && ! $allCompanies) {
            $this->error('You must specify either --company or --all-companies.');

            return 2;
        }

        if (($companyUuid !== null && $companyUuid !== '') && $allCompanies) {
            $this->error('You cannot specify both --company and --all-companies.');

            return 2;
        }

        if ($dryRun && $execute) {
            $this->error('You cannot specify both --dry-run and --execute.');

            return 2;
        }

        if (! $dryRun && ! $execute) {
            $dryRun = true;
        }

        $format = $this->option('format') ?? 'table';
        if (! in_array($format, ['table', 'json'], true)) {
            $format = 'table';
        }

        $olderThan = filter_var($this->option('older-than'), FILTER_VALIDATE_INT);
        if ($olderThan === false || $olderThan < 1) {
            $olderThan = 24;
        }

        $minSafeHours = (int) config('catalog.media.cleanup_older_than_hours', 24);
        if ($olderThan < $minSafeHours) {
            $this->warn(sprintf(
                '--older-than value %d is below the minimum safe value of %d hours. Using %d instead.',
                $olderThan,
                $minSafeHours,
                $minSafeHours,
            ));
            $olderThan = $minSafeHours;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1) {
            $limit = 1000;
        }

        $maxLimit = ((int) config('catalog.media.cleanup_limit', 500)) * 2;
        if ($limit > $maxLimit) {
            $this->warn(sprintf(
                '--limit value %d exceeds maximum allowed %d. Using %d instead.',
                $limit,
                $maxLimit,
                $maxLimit,
            ));
            $limit = $maxLimit;
        }

        try {
            if ($companyUuid !== null && $companyUuid !== '') {
                if (! Str::isUuid($companyUuid)) {
                    $this->error('The --company option must be a valid company UUID.');

                    return 2;
                }

                $company = Company::withTrashed()->where('uuid', $companyUuid)->first();

                if ($company === null) {
                    $this->error('The requested company UUID was not found.');

                    return 3;
                }

                $report = $cleanupService->cleanup($company, $dryRun, $olderThan, $limit);
                $reports = [$report];
            } else {
                $reports = [];
                Company::query()->chunkById(500, function ($chunk) use ($cleanupService, $dryRun, $olderThan, $limit, &$reports): void {
                    foreach ($chunk as $company) {
                        $reports[] = $cleanupService->cleanup($company, $dryRun, $olderThan, $limit);
                    }
                });
            }

            if ($reports === []) {
                $this->info('No companies found.');

                return 0;
            }

            if ($format === 'json') {
                $output = array_map(fn ($report) => $report->toArray(), $reports);
                $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $totalScanned = 0;
                $totalCandidates = 0;
                $totalDeleted = 0;
                $totalSkipped = 0;
                $totalFailed = 0;
                $totalBytes = 0;

                $rows = [];
                foreach ($reports as $i => $report) {
                    $totalScanned += $report->scanned;
                    $totalCandidates += $report->candidates;
                    $totalDeleted += $report->deleted;
                    $totalSkipped += $report->skipped;
                    $totalFailed += $report->failed;
                    $totalBytes += $report->bytesReclaimed;

                    $companyLabel = $companyUuid !== null && $companyUuid !== ''
                        ? ($company->name ?? $companyUuid)
                        : sprintf('Report %d', $i + 1);

                    $rows[] = [
                        $companyLabel,
                        $report->scanned,
                        $report->candidates,
                        $report->deleted,
                        $report->skipped,
                        $report->failed,
                        $this->formatBytes($report->bytesReclaimed),
                    ];
                }

                $this->table(
                    ['Company', 'Scanned', 'Candidates', 'Deleted', 'Skipped', 'Failed', 'Bytes Reclaimed'],
                    $rows,
                );

                $mode = $dryRun ? 'DRY RUN' : 'EXECUTION';
                $this->info(sprintf(
                    '[%s] Total - scanned: %d, candidates: %d, deleted: %d, skipped: %d, failed: %d, bytes: %s.',
                    $mode,
                    $totalScanned,
                    $totalCandidates,
                    $totalDeleted,
                    $totalSkipped,
                    $totalFailed,
                    $this->formatBytes($totalBytes),
                ));
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error(sprintf('Operational failure: %s', $e->getMessage()));

            return 3;
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $index = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return sprintf('%.2f %s', $bytes / (1024 ** $index), $units[$index]);
    }
}
