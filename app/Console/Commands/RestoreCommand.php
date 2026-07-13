<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class RestoreCommand extends Command
{
    protected $signature = 'nordipass:restore
        {backup-id : The UUID of the backup to restore}
        {--force : Skip safety checks for production}
        {--confirm-production-restore : Explicit confirmation for production restore}
        {--target-connection= : Database connection to restore into (default: current connection)}';

    protected $description = 'Restore a database backup';

    public function handle(): int
    {
        $backupId = $this->argument('backup-id');
        $targetConnection = $this->option('target-connection') ?? config('database.default');
        $isProduction = config('app.env') === 'production';

        if ($isProduction && ! $this->option('force')) {
            $this->error('Production restore requires --force.');

            return 1;
        }

        if ($isProduction && ! $this->option('confirm-production-restore')) {
            $this->error('Production restore requires --confirm-production-restore.');

            return 1;
        }

        if ($isProduction && ! $this->confirm('Type RESTORE NORDIPASS PRODUCTION to confirm:')) {
            $this->error('Restore cancelled.');

            return 1;
        }

        $disk = Storage::disk(config('backup.disk'));
        $backupPath = config('backup.path').'/'.$backupId;

        if (! $disk->exists($backupPath.'/manifest.json')) {
            $this->error("Backup '{$backupId}' not found.");

            return 1;
        }

        $manifestContent = $disk->get($backupPath.'/manifest.json');
        $manifest = json_decode($manifestContent, true);

        if ($manifest === null || ($manifest['status'] ?? null) !== 'complete') {
            $this->error('Backup is incomplete or invalid.');

            return 1;
        }

        $this->verifyChecksums($disk, $backupPath, $manifest);

        $dbConfig = config("database.connections.{$targetConnection}");

        if ($dbConfig === null) {
            $this->error("Target connection '{$targetConnection}' not configured.");

            return 1;
        }

        if (! isset($manifest['artifacts']['database'])) {
            $this->error('No database artifact in this backup.');

            return 1;
        }

        $artifact = $manifest['artifacts']['database'];
        $localDump = tempnam(sys_get_temp_dir(), 'restore_').'.sql';
        $localGz = $localDump.'.gz';
        file_put_contents($localGz, $disk->get($backupPath.'/'.$artifact['path']));

        $decompressed = gzdecode(file_get_contents($localGz));
        if ($decompressed === false) {
            throw new \RuntimeException('Failed to decompress database artifact.');
        }
        file_put_contents($localDump, $decompressed);

        $mysqlBinary = $this->findMysqlBinary();

        $this->line('Database dump size: '.round(strlen($decompressed) / 1024).' KB');
        $this->line('Restoring database...');

        $credentialFile = sys_get_temp_dir().'/.restore_my.cnf';
        file_put_contents($credentialFile, $this->buildMyCnf($dbConfig));
        chmod($credentialFile, 0600);

        try {
            $command = [
                $mysqlBinary,
                '--defaults-extra-file='.$credentialFile,
                '--host='.$dbConfig['host'],
                '--port='.$dbConfig['port'],
                $dbConfig['database'],
            ];

            $process = new Process($command);
            $process->setTimeout(600);
            $process->setInput($decompressed);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Database restore failed: '.$process->getErrorOutput());
            }

            $this->info('Database restored successfully.');
        } finally {
            if (file_exists($localDump)) {
                unlink($localDump);
            }
            if (file_exists($localGz)) {
                unlink($localGz);
            }
            if (file_exists($credentialFile)) {
                unlink($credentialFile);
            }
        }

        return 0;
    }

    private function verifyChecksums($disk, string $backupPath, array $manifest): void
    {
        foreach ($manifest['artifacts'] as $type => $artifact) {
            $localPath = tempnam(sys_get_temp_dir(), 'restore_verify_');
            file_put_contents($localPath, $disk->get($backupPath.'/'.$artifact['path']));

            $actualSha = hash_file('sha256', $localPath);
            unlink($localPath);

            if ($actualSha !== $artifact['sha256']) {
                throw new \RuntimeException("Checksum mismatch for {$type} artifact.");
            }
        }

        $this->line('All checksums verified.');
    }

    private function findMysqlBinary(): string
    {
        $candidates = ['mysql', 'mysql.exe', 'mariadb', 'mariadb.exe'];

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

        throw new \RuntimeException('MySQL client binary not found. Install mysql client.');
    }

    private function buildMyCnf(array $config): string
    {
        $content = "[client]\n";
        $content .= "user={$config['username']}\n";
        $content .= "password={$config['password']}\n";

        return $content;
    }
}
