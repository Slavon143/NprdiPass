<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class BackupCommand extends Command
{
    protected $signature = 'nordipass:backup
        {--database-only : Backup only the database}
        {--files-only : Backup only the application files}
        {--dry-run : Validate configuration without creating a backup}
        {--no-verify : Skip post-backup verification}';

    protected $description = 'Create a full database and files backup';

    private string $backupId;

    private string $tempDir;

    private array $manifest;

    public function handle(): int
    {
        if (! config('backup.enabled')) {
            $this->error('Backup is disabled. Set BACKUP_ENABLED=true to enable.');

            return 2;
        }

        if (! $this->option('database-only') && ! $this->option('files-only')) {
            $this->line('Backup scope: database + files');
        } elseif ($this->option('database-only')) {
            $this->line('Backup scope: database only');
        } else {
            $this->line('Backup scope: files only');
        }

        if ($this->option('dry-run')) {
            $this->showDryRun();

            return 0;
        }

        if (! Cache::lock('nordipass:infrastructure:backup', config('backup.lock_minutes'))->get()) {
            $this->error('A backup is already running.');

            return 3;
        }

        try {
            $this->backupId = (string) Str::uuid();
            $this->tempDir = Storage::path('private/backup-temp/'.$this->backupId);

            $this->info("Backup ID: {$this->backupId}");
            $this->line('Starting backup...');

            $this->initializeManifest();

            if (! $this->option('files-only') && config('backup.database.enabled')) {
                $this->createDatabaseDump();
            }

            if (! $this->option('database-only') && config('backup.files.enabled')) {
                $this->createFilesArchive();
            }

            $this->finalizeManifest();

            if (! $this->option('no-verify') && config('backup.verify_after_create')) {
                $this->verifyBackup();
            }

            $this->storeArtifacts();

            $this->info('Backup completed successfully.');

            Log::info('Backup completed', [
                'backup_id' => $this->backupId,
                'status' => 'complete',
                'manifest' => $this->manifest,
            ]);

            return 0;
        } catch (\Throwable $e) {
            $this->error("Backup failed: {$e->getMessage()}");

            Log::error('Backup failed', [
                'backup_id' => $this->backupId ?? null,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return 1;
        } finally {
            $this->cleanupTemp();
            Cache::lock('nordipass:infrastructure:backup')->forceRelease();
        }
    }

    private function showDryRun(): void
    {
        $this->table(['Setting', 'Value'], [
            ['Disk', config('backup.disk')],
            ['Path', config('backup.path')],
            ['Database backup', config('backup.database.enabled') ? 'yes' : 'no'],
            ['Database driver', config('database.default')],
            ['Files backup', config('backup.files.enabled') ? 'yes' : 'no'],
            ['Include directories', implode(', ', config('backup.files.include'))],
            ['Compression', config('backup.compression')],
            ['Verify after create', config('backup.verify_after_create') ? 'yes' : 'no'],
            ['Retention daily', (string) config('backup.retention.daily')],
            ['Retention weekly', (string) config('backup.retention.weekly')],
            ['Retention monthly', (string) config('backup.retention.monthly')],
        ]);
    }

    private function initializeManifest(): void
    {
        $this->manifest = [
            'version' => 1,
            'backup_id' => $this->backupId,
            'application' => config('app.name'),
            'environment' => config('app.env'),
            'created_at' => now()->toIso8601String(),
            'status' => 'incomplete',
            'database_driver' => config('database.default'),
            'artifacts' => [],
        ];
    }

    private function finalizeManifest(): void
    {
        $this->manifest['status'] = 'complete';
    }

    private function createDatabaseDump(): void
    {
        $this->line('Creating database dump...');

        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        if ($dbConfig === null) {
            throw new \RuntimeException("Database connection '{$connection}' not configured.");
        }

        $binary = $this->findDatabaseBinary();

        $dumpFile = "{$this->tempDir}/database.sql";
        $compressedFile = "{$dumpFile}.gz";

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0700, true);
        }

        $credentialFile = "{$this->tempDir}/.my.cnf";
        file_put_contents($credentialFile, $this->buildMyCnf($dbConfig));
        chmod($credentialFile, 0600);

        try {
            $command = [
                $binary,
                '--defaults-extra-file='.$credentialFile,
                '--host='.$dbConfig['host'],
                '--port='.$dbConfig['port'],
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--result-file='.$dumpFile,
                $dbConfig['database'],
            ];

            $process = new Process($command);
            $process->setTimeout(300);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException('mysqldump failed: '.$process->getErrorOutput());
            }

            if (! file_exists($dumpFile) || filesize($dumpFile) === 0) {
                throw new \RuntimeException('Database dump is empty.');
            }

            $this->compressFile($dumpFile);
            $uncompressedSize = filesize($dumpFile);

            if (file_exists($compressedFile)) {
                unlink($dumpFile);
            }

            $sha256 = hash_file('sha256', $compressedFile);

            $this->manifest['artifacts']['database'] = [
                'path' => basename($compressedFile),
                'size' => filesize($compressedFile),
                'uncompressed_size' => $uncompressedSize,
                'sha256' => $sha256,
            ];

            $this->line('Database dump created.');
        } finally {
            if (file_exists($credentialFile)) {
                unlink($credentialFile);
            }
        }
    }

    private function createFilesArchive(): void
    {
        $this->line('Creating files archive...');

        $includeDirs = config('backup.files.include');
        $excludePatterns = config('backup.files.exclude');
        $archiveFile = "{$this->tempDir}/files.tar.gz";

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0700, true);
        }

        $phar = new \PharData($archiveFile);

        foreach ($includeDirs as $dir) {
            if (! is_dir($dir)) {
                $this->warn("Directory not found, skipping: {$dir}");

                continue;
            }

            $realDir = realpath($dir);
            $relativeBase = basename($realDir);

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($realDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                $relativePath = $relativeBase.'/'.$iterator->getSubPathname();

                foreach ($excludePatterns as $pattern) {
                    if (fnmatch($pattern, $file->getFilename())) {
                        continue 2;
                    }
                }

                $phar->addFile($file->getPathname(), $relativePath);
            }
        }

        $sha256 = hash_file('sha256', $archiveFile);

        $this->manifest['artifacts']['files'] = [
            'path' => basename($archiveFile),
            'size' => filesize($archiveFile),
            'sha256' => $sha256,
        ];

        $this->line('Files archive created.');
    }

    private function verifyBackup(): void
    {
        $this->line('Verifying backup...');

        foreach ($this->manifest['artifacts'] as $type => $artifact) {
            $path = "{$this->tempDir}/{$artifact['path']}";

            if (! file_exists($path)) {
                throw new \RuntimeException("Artifact missing: {$artifact['path']}");
            }

            $actualSha = hash_file('sha256', $path);
            if ($actualSha !== $artifact['sha256']) {
                throw new \RuntimeException("Checksum mismatch for {$artifact['path']}");
            }

            $this->line("  {$type}: checksum OK ({$artifact['sha256']})");
        }

        $this->line('Verification passed.');
    }

    private function storeArtifacts(): void
    {
        $disk = Storage::disk(config('backup.disk'));
        $backupPath = config('backup.path').'/'.$this->backupId;

        foreach ($this->manifest['artifacts'] as $type => $artifact) {
            $sourcePath = "{$this->tempDir}/{$artifact['path']}";
            $disk->putFileAs($backupPath, new File($sourcePath), $artifact['path']);
        }

        $manifestPath = "{$this->tempDir}/manifest.json";
        file_put_contents($manifestPath, json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $disk->putFileAs($backupPath, new File($manifestPath), 'manifest.json');

        $this->line("Backup stored on disk '{$this->getDiskName()}' in path '{$backupPath}'.");
    }

    private function getDiskName(): string
    {
        return config('backup.disk');
    }

    private function findDatabaseBinary(): string
    {
        $configured = config('backup.database.binary');

        if ($configured !== '') {
            return $configured;
        }

        $candidates = ['mysqldump', 'mysqldump.exe', 'mariadb-dump', 'mariadb-dump.exe'];

        $commonPaths = [
            'C:\Program Files\MySQL\MySQL Server 8.0\bin',
            'C:\Program Files\MySQL\MySQL Server 8.4\bin',
            'C:\Program Files\MariaDB 10.11\bin',
            'C:\Program Files\MariaDB 11.0\bin',
            'C:\xampp\mysql\bin',
            'C:\laragon\bin\mysql\mysql-8.0\bin',
            'C:\OSPanel\modules\database\MySQL-8.0\bin',
        ];

        foreach ($commonPaths as $path) {
            foreach ($candidates as $binary) {
                $fullPath = $path.DIRECTORY_SEPARATOR.$binary;
                if (file_exists($fullPath)) {
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

        throw new \RuntimeException(
            'Database dump binary not found. Install mysqldump or set BACKUP_DATABASE_BINARY.',
        );
    }

    private function buildMyCnf(array $config): string
    {
        $content = "[client]\n";
        $content .= "user={$config['username']}\n";
        $content .= "password={$config['password']}\n";

        return $content;
    }

    private function compressFile(string $path): void
    {
        $compressed = $path.'.gz';
        $data = file_get_contents($path);
        file_put_contents($compressed, gzencode($data, 6));
    }

    private function cleanupTemp(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->rrmdir($this->tempDir);
        }
    }

    private function rrmdir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
