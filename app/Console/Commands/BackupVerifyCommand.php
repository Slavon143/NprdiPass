<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupVerifyCommand extends Command
{
    protected $signature = 'nordipass:backup-verify {backup-id : The UUID of the backup to verify}';

    protected $description = 'Verify a backup by checking manifest and artifact checksums';

    public function handle(): int
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

        if (($manifest['version'] ?? null) !== 1) {
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

        $allOk = true;

        foreach ($manifest['artifacts'] as $type => $artifact) {
            $path = $backupPath.'/'.$artifact['path'];

            if (! $disk->exists($path)) {
                $this->error("  {$type}: artifact missing ({$artifact['path']})");
                $allOk = false;

                continue;
            }

            $localPath = tempnam(sys_get_temp_dir(), 'backup_verify_');
            file_put_contents($localPath, $disk->get($path));

            $actualSha = hash_file('sha256', $localPath);
            unlink($localPath);

            if ($actualSha === $artifact['sha256']) {
                $this->info("  {$type}: checksum OK ({$artifact['size']} bytes)");
            } else {
                $this->error("  {$type}: checksum MISMATCH (expected {$artifact['sha256']}, got {$actualSha})");
                $allOk = false;
            }
        }

        return $allOk ? 0 : 4;
    }
}
