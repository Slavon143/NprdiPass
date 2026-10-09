<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\PersistentFileArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupVerifyCommand extends Command
{
    protected $signature = 'nordipass:backup-verify {backup-id : The UUID of the backup to verify}';

    protected $description = 'Verify a backup by checking manifest and artifact checksums';

    public function handle(PersistentFileArchive $fileArchive): int
    {
        $backupId = $this->argument('backup-id');
        $disk = Storage::disk(config('backup.disk'));
        $backupPath = config('backup.path').'/'.$backupId;

        if (! $disk->exists($backupPath.'/manifest.json')) {
            $this->error("Backup '{$backupId}' not found or manifest missing.");

            return 1;
        }

        $manifestContent = $disk->get($backupPath.'/manifest.json');
        $manifest = json_decode($manifestContent, true);

        if ($manifest === null) {
            $this->error('Manifest is invalid JSON.');

            return 1;
        }

        if (($manifest['version'] ?? null) !== 2) {
            $this->error('Unsupported manifest version.');

            return 1;
        }

        if (($manifest['status'] ?? null) !== 'complete') {
            $this->error('Backup status is not complete.');

            return 1;
        }

        $this->info("Backup ID: {$manifest['backup_id']}");
        $this->line("Created: {$manifest['created_at']}");
        $this->line("Environment: {$manifest['environment']}");

        if (! is_array($manifest['scope'] ?? null) || ! is_array($manifest['artifacts'] ?? null)) {
            $this->error('Manifest scope or artifacts are invalid.');

            return 1;
        }

        $allOk = true;

        foreach ($manifest['artifacts'] as $type => $artifact) {
            if (! is_array($artifact) || ! is_string($artifact['path'] ?? null)
                || ! is_int($artifact['size'] ?? null) || $artifact['size'] <= 0
                || ! is_string($artifact['sha256'] ?? null)) {
                $this->error("  {$type}: artifact metadata invalid");
                $allOk = false;

                continue;
            }

            $path = $backupPath.'/'.$artifact['path'];

            if (! $disk->exists($path)) {
                $this->error("  {$type}: artifact missing ({$artifact['path']})");
                $allOk = false;

                continue;
            }

            $localPath = tempnam(sys_get_temp_dir(), 'backup_verify_');
            $source = $disk->readStream($path);
            $target = fopen($localPath, 'wb');
            if (! is_resource($source) || ! is_resource($target)) {
                @unlink($localPath);
                $this->error("  {$type}: artifact could not be read");
                $allOk = false;

                continue;
            }
            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);

            $actualSha = hash_file('sha256', $localPath);
            $actualSize = filesize($localPath);

            if ($actualSha === $artifact['sha256'] && $actualSize === $artifact['size']) {
                if ($type === 'files') {
                    try {
                        $fileArchive->inspect($localPath, $artifact['stores'] ?? []);
                    } catch (\Throwable $exception) {
                        $this->error("  files: archive invalid ({$exception->getMessage()})");
                        $allOk = false;
                        unlink($localPath);

                        continue;
                    }
                }
                $this->info("  {$type}: checksum OK ({$artifact['size']} bytes)");
            } else {
                $this->error("  {$type}: checksum MISMATCH (expected {$artifact['sha256']}, got {$actualSha})");
                $allOk = false;
            }

            unlink($localPath);
        }

        return $allOk ? 0 : 4;
    }
}
