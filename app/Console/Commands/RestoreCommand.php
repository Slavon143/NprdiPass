<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\PersistentFileArchive;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class RestoreCommand extends Command
{
    protected $signature = 'nordipass:restore
        {backup-id : The UUID of the backup to restore}
        {--force : Skip safety checks for production}
        {--confirm-production-restore : Explicit confirmation for production restore}
        {--database-only : Restore only the database artifact}
        {--files-only : Restore only persistent file assets}
        {--target-connection= : Database connection to restore into (default: current connection)}';

    protected $description = 'Restore a complete NordiPass database and persistent-file backup';

    public function handle(PersistentFileArchive $fileArchive): int
    {
        if ($this->option('database-only') && $this->option('files-only')) {
            $this->error('Choose at most one of --database-only and --files-only.');

            return 1;
        }
        if (! $this->productionSafetyAccepted()) {
            return 1;
        }

        $backupId = (string) $this->argument('backup-id');
        $disk = Storage::disk(config('backup.disk'));
        $backupPath = rtrim((string) config('backup.path'), '/').'/'.$backupId;
        $workDir = rtrim((string) config('backup.temporary_path'), '/\\').DIRECTORY_SEPARATOR.'restore-'.Str::uuid();

        try {
            $fileArchive->assertOutputSeparated((string) config('backup.temporary_path'));
            $manifest = $this->loadManifest($disk, $backupPath, $backupId);
            $restoreDatabase = ! $this->option('files-only');
            $restoreFiles = ! $this->option('database-only');
            $this->validateScope($manifest, $restoreDatabase, $restoreFiles);
            $this->ensureDirectory($workDir);
            $localArtifacts = $this->downloadAndVerifyArtifacts($disk, $backupPath, $manifest, $workDir);

            if ($restoreFiles) {
                $fileArchive->inspect($localArtifacts['files'], $manifest['artifacts']['files']['stores']);
                $this->line('Persistent file archive structure and contents verified.');
            }
            if ($restoreDatabase) {
                $this->restoreDatabase($localArtifacts['database']);
            }
            if ($restoreFiles) {
                $fileArchive->restore($localArtifacts['files'], $manifest['artifacts']['files']['stores']);
                $this->info('Persistent customer files restored and verified.');
            }

            $this->info($restoreDatabase && $restoreFiles
                ? 'Full database and files restore completed successfully.'
                : 'Requested restore scope completed successfully.');

            return 0;
        } catch (\Throwable $exception) {
            $this->error('Restore failed: '.$exception->getMessage());
            $this->warn('The restore did not complete successfully. Enable maintenance mode (php artisan down), retry, or restore the pre-restore safety backup.');

            return 1;
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    private function productionSafetyAccepted(): bool
    {
        if (config('app.env') !== 'production') {
            return true;
        }
        if (! $this->option('force')) {
            $this->error('Production restore requires --force.');

            return false;
        }
        if (! $this->option('confirm-production-restore')) {
            $this->error('Production restore requires --confirm-production-restore.');

            return false;
        }
        if (! $this->confirm('Type RESTORE NORDIPASS PRODUCTION to confirm:')) {
            $this->error('Restore cancelled.');

            return false;
        }

        return true;
    }

    private function loadManifest(FilesystemAdapter $disk, string $backupPath, string $backupId): array
    {
        $path = $backupPath.'/manifest.json';
        if (! $disk->exists($path)) {
            throw new RuntimeException("Backup '{$backupId}' not found or manifest missing.");
        }
        $manifest = json_decode($disk->get($path), true);
        if (! is_array($manifest) || ($manifest['version'] ?? null) !== 2
            || ($manifest['status'] ?? null) !== 'complete' || ($manifest['backup_id'] ?? null) !== $backupId
            || ! is_array($manifest['scope'] ?? null) || ! is_array($manifest['artifacts'] ?? null)) {
            throw new RuntimeException('Backup manifest is invalid, incomplete, or unsupported.');
        }

        return $manifest;
    }

    private function validateScope(array $manifest, bool $restoreDatabase, bool $restoreFiles): void
    {
        if ($restoreDatabase && (($manifest['scope']['database'] ?? false) !== true || ! isset($manifest['artifacts']['database']))) {
            throw new RuntimeException('This backup does not contain a database artifact.');
        }
        if ($restoreFiles && (($manifest['scope']['files'] ?? false) !== true || ! isset($manifest['artifacts']['files']))) {
            throw new RuntimeException('This backup does not contain a persistent files artifact.');
        }
        foreach ($manifest['artifacts'] as $name => $artifact) {
            if (! is_array($artifact) || ! is_string($artifact['path'] ?? null)
                || preg_match('/\A[a-zA-Z0-9._-]+\z/', $artifact['path']) !== 1
                || ! is_int($artifact['size'] ?? null) || $artifact['size'] <= 0
                || ! is_string($artifact['sha256'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', $artifact['sha256']) !== 1) {
                throw new RuntimeException("Manifest artifact metadata is invalid for '{$name}'.");
            }
        }
        if ($restoreFiles) {
            $files = $manifest['artifacts']['files'];
            if (($files['format'] ?? null) !== 'tar+gzip' || ! is_array($files['stores'] ?? null)) {
                throw new RuntimeException('Persistent files manifest metadata is invalid.');
            }
        }
    }

    /** @return array<string, string> */
    private function downloadAndVerifyArtifacts(FilesystemAdapter $disk, string $backupPath, array $manifest, string $workDir): array
    {
        $local = [];
        foreach ($manifest['artifacts'] as $type => $artifact) {
            $remotePath = $backupPath.'/'.$artifact['path'];
            if (! $disk->exists($remotePath)) {
                throw new RuntimeException("Required {$type} artifact is missing.");
            }
            $localPath = $workDir.DIRECTORY_SEPARATOR.$artifact['path'];
            $source = $disk->readStream($remotePath);
            $target = fopen($localPath, 'xb');
            if (! is_resource($source) || ! is_resource($target)) {
                throw new RuntimeException("Could not stage {$type} artifact for restore.");
            }
            try {
                if (stream_copy_to_stream($source, $target) === false) {
                    throw new RuntimeException("Could not stage {$type} artifact for restore.");
                }
            } finally {
                fclose($source);
                fclose($target);
            }
            if (filesize($localPath) !== $artifact['size']
                || ! hash_equals($artifact['sha256'], (string) hash_file('sha256', $localPath))) {
                throw new RuntimeException("Checksum or size mismatch for {$type} artifact.");
            }
            $local[$type] = $localPath;
        }
        $this->line('All required backup artifacts passed checksum and size verification.');

        return $local;
    }

    private function restoreDatabase(string $compressedPath): void
    {
        $targetConnection = $this->option('target-connection') ?? config('database.default');
        $dbConfig = config("database.connections.{$targetConnection}");
        if (! is_array($dbConfig)) {
            throw new RuntimeException("Target connection '{$targetConnection}' is not configured.");
        }
        $compressed = file_get_contents($compressedPath);
        $sql = is_string($compressed) ? gzdecode($compressed) : false;
        if (! is_string($sql) || $sql === '') {
            throw new RuntimeException('Database artifact is not a valid non-empty gzip dump.');
        }

        $databaseName = (string) ($dbConfig['database'] ?? '');
        if ($databaseName === '' || ! preg_match('/\A[a-zA-Z0-9_-]+\z/', $databaseName)) {
            throw new RuntimeException('The target connection does not define a usable database name.');
        }
        $quotedDatabase = '`'.str_replace('`', '``', $databaseName).'`';
        $sql = "CREATE DATABASE IF NOT EXISTS {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE {$quotedDatabase};\n".$sql;

        $credentialFile = dirname($compressedPath).DIRECTORY_SEPARATOR.'.restore-my.cnf';
        file_put_contents($credentialFile, $this->buildMyCnf($dbConfig));
        chmod($credentialFile, 0600);
        try {
            $process = new Process([
                $this->findMysqlBinary(),
                '--defaults-extra-file='.$credentialFile,
                '--host='.$dbConfig['host'],
                '--port='.$dbConfig['port'],
            ]);
            $process->setTimeout(600);
            $process->setInput($sql);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Database restore failed: '.$process->getErrorOutput());
            }
        } finally {
            @unlink($credentialFile);
        }
        $this->info('Database restored successfully.');
    }

    private function findMysqlBinary(): string
    {
        $configured = (string) config('backup.database.restore_binary');
        if ($configured !== '') {
            return $configured;
        }
        $candidates = ['mysql', 'mysql.exe', 'mariadb', 'mariadb.exe'];
        $commonPaths = [
            'C:\Program Files\MySQL\MySQL Server 8.0\bin', 'C:\Program Files\MySQL\MySQL Server 8.4\bin',
            'C:\Program Files\MariaDB 10.11\bin', 'C:\Program Files\MariaDB 11.0\bin', 'C:\xampp\mysql\bin',
            'C:\laragon\bin\mysql\mysql-8.0\bin', 'C:\OSPanel\modules\database\MySQL-8.0\bin',
        ];
        foreach ($commonPaths as $path) {
            foreach ($candidates as $binary) {
                $fullPath = $path.DIRECTORY_SEPARATOR.$binary;
                if (is_file($fullPath)) {
                    return $fullPath;
                }
            }
        }
        foreach ($candidates as $binary) {
            $process = new Process([$binary, '--version']);
            $process->run();
            if ($process->isSuccessful()) {
                return $binary;
            }
        }
        throw new RuntimeException('MySQL client binary not found. Install mysql client or set RESTORE_DATABASE_BINARY.');
    }

    private function buildMyCnf(array $config): string
    {
        return "[client]\nuser={$config['username']}\npassword={$config['password']}\n";
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException("Could not create restore working directory '{$path}'.");
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
