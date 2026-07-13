<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class BackupPruneCommand extends Command
{
    protected $signature = 'nordipass:backup-prune
        {--dry-run : Show what would be pruned without deleting}';

    protected $description = 'Prune old backups according to retention policy';

    public function handle(): int
    {
        $disk = Storage::disk(config('backup.disk'));
        $backupPath = config('backup.path');

        if (! $disk->exists($backupPath)) {
            $this->info("Backup path '{$backupPath}' is empty.");

            return 0;
        }

        if (! Cache::lock('nordipass:infrastructure:backup-prune', 60)->get()) {
            $this->error('A prune operation is already running.');

            return 3;
        }

        try {
            $backups = $this->collectBackups($disk, $backupPath);

            if (count($backups) === 0) {
                $this->info('No backups found.');

                return 0;
            }

            $toKeep = $this->applyRetention($backups);
            $toDelete = array_diff($backups, $toKeep);

            $this->table(['Backup', 'Created', 'Action'], array_map(
                fn (string $id) => [
                    $id,
                    $this->getBackupTimestamp($disk, $backupPath, $id),
                    in_array($id, $toKeep, true) ? 'keep' : 'delete',
                ],
                $backups,
            ));

            $this->line(sprintf('Keeping: %d, Deleting: %d', count($toKeep), count($toDelete)));

            if ($this->option('dry-run') || count($toDelete) === 0) {
                return 0;
            }

            foreach ($toDelete as $id) {
                $this->deleteBackupSet($disk, $backupPath, $id);
                $this->line("Deleted: {$id}");
            }

            $this->info('Prune completed.');

            return 0;
        } finally {
            Cache::lock('nordipass:infrastructure:backup-prune')->forceRelease();
        }
    }

    private function collectBackups($disk, string $basePath): array
    {
        $directories = $disk->directories($basePath);
        $backups = [];

        foreach ($directories as $dir) {
            $id = basename($dir);

            if ($disk->exists($dir.'/manifest.json')) {
                $backups[] = $id;
            }
        }

        sort($backups);

        return $backups;
    }

    private function applyRetention(array $backups): array
    {
        $daily = (int) config('backup.retention.daily', 7);
        $weekly = (int) config('backup.retention.weekly', 4);
        $monthly = (int) config('backup.retention.monthly', 3);

        $toKeep = [];
        $grouped = [];

        $disk = Storage::disk(config('backup.disk'));
        $basePath = config('backup.path');

        foreach ($backups as $id) {
            $manifestPath = $basePath.'/'.$id.'/manifest.json';
            if (! $disk->exists($manifestPath)) {
                continue;
            }

            $manifest = json_decode($disk->get($manifestPath), true);
            $createdAt = $manifest['created_at'] ?? null;

            if ($createdAt === null) {
                continue;
            }

            try {
                $date = Carbon::parse($createdAt);
                $key = $date->format('Ymd');
                if (! isset($grouped[$key])) {
                    $grouped[$key] = $id;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        krsort($grouped);

        $dailyCount = 0;
        $weeklyCount = 0;
        $monthlyCount = 0;

        foreach ($grouped as $dateKey => $id) {
            try {
                $date = Carbon::createFromFormat('Ymd', $dateKey);
            } catch (\Throwable) {
                continue;
            }

            $isMonthly = $date->day === 1;
            $isWeekly = $date->dayOfWeekIso === 1 && ! $isMonthly;

            if ($isMonthly && $monthlyCount < $monthly) {
                $toKeep[] = $id;
                $monthlyCount++;
            } elseif ($isWeekly && $weeklyCount < $weekly) {
                $toKeep[] = $id;
                $weeklyCount++;
            } elseif ($dailyCount < $daily) {
                $toKeep[] = $id;
                $dailyCount++;
            }
        }

        if (empty($toKeep) && ! empty($backups)) {
            $toKeep[] = $backups[count($backups) - 1];
        }

        return $toKeep;
    }

    private function getBackupTimestamp($disk, string $basePath, string $id): string
    {
        $manifestContent = $disk->exists($basePath.'/'.$id.'/manifest.json')
            ? $disk->get($basePath.'/'.$id.'/manifest.json')
            : null;

        if ($manifestContent) {
            $manifest = json_decode($manifestContent, true);

            return $manifest['created_at'] ?? $id;
        }

        return $id;
    }

    private function deleteBackupSet($disk, string $basePath, string $id): void
    {
        $targetPath = $basePath.'/'.$id;
        $realBase = realpath(Storage::disk(config('backup.disk'))->path(''));

        if ($realBase === false) {
            throw new \RuntimeException('Cannot resolve backup disk root.');
        }

        $resolved = realpath($disk->path($targetPath));

        if ($resolved === false || ! str_starts_with($resolved, $realBase)) {
            $this->error("Path traversal detected for: {$id}");

            return;
        }

        $disk->deleteDirectory($targetPath);
    }
}
