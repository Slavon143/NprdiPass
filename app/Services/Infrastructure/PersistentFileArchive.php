<?php

namespace App\Services\Infrastructure;

use FilesystemIterator;
use Illuminate\Support\Str;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class PersistentFileArchive
{
    /** @return list<array{disk: string, original_root: string, archive_root: string, file_count: int, total_bytes: int, content_sha256: string}> */
    public function create(string $archivePath): array
    {
        $stores = $this->configuredStores();
        $tarPath = preg_replace('/\.gz\z/', '', $archivePath) ?: $archivePath.'.tar';
        $this->ensureDirectory(dirname($tarPath));
        @unlink($tarPath);
        @unlink($archivePath);

        $archive = new PharData($tarPath);
        $manifestStores = [];

        foreach ($stores as $store) {
            $archive->addEmptyDir($store['archive_root']);
            $files = $this->filesIn($store['root']);
            $fingerprints = [];
            $totalBytes = 0;

            foreach ($files as $relative => $absolute) {
                $archivePathname = $store['archive_root'].'/'.$relative;
                $archive->addFile($absolute, $archivePathname);
                $size = $this->fileSize($absolute);
                $totalBytes += $size;
                $fingerprints[$relative] = $this->fingerprint($relative, $size, $this->fileHash($absolute));
            }

            $manifestStores[] = [
                'disk' => $store['disk'],
                'original_root' => $store['root'],
                'archive_root' => $store['archive_root'],
                'file_count' => count($files),
                'total_bytes' => $totalBytes,
                'content_sha256' => $this->aggregate($fingerprints),
            ];
        }

        unset($archive);
        $tar = new PharData($tarPath);
        $tar->compress(Phar::GZ);
        unset($tar);
        @unlink($tarPath);

        if (! is_file($archivePath) || $this->fileSize($archivePath) === 0) {
            throw new RuntimeException('Persistent files archive was not created.');
        }

        return $manifestStores;
    }

    /**
     * @param  list<array<string, mixed>>  $manifestStores
     * @return list<array{disk: string, root: string, archive_root: string, file_count: int, total_bytes: int, content_sha256: string}>
     */
    public function validateManifestStores(array $manifestStores): array
    {
        $configured = collect($this->configuredStores())->keyBy('disk');
        $validated = [];

        if (count($manifestStores) !== $configured->count()) {
            throw new RuntimeException('File manifest does not contain every configured persistent disk.');
        }

        foreach ($manifestStores as $store) {
            $disk = $store['disk'] ?? null;
            if (! is_string($disk) || ! $configured->has($disk)) {
                throw new RuntimeException('File manifest contains an unknown persistent disk.');
            }

            $expected = $configured->get($disk);
            $archiveRoot = $store['archive_root'] ?? null;
            $fileCount = $store['file_count'] ?? null;
            $totalBytes = $store['total_bytes'] ?? null;
            $contentSha = $store['content_sha256'] ?? null;

            if ($archiveRoot !== $expected['archive_root']
                || ! is_int($fileCount) || $fileCount < 0
                || ! is_int($totalBytes) || $totalBytes < 0
                || ! is_string($contentSha) || preg_match('/\A[0-9a-f]{64}\z/', $contentSha) !== 1) {
                throw new RuntimeException("File manifest metadata is invalid for disk '{$disk}'.");
            }

            $validated[] = [
                'disk' => $disk,
                'root' => $expected['root'],
                'archive_root' => $archiveRoot,
                'file_count' => $fileCount,
                'total_bytes' => $totalBytes,
                'content_sha256' => $contentSha,
            ];
        }

        if (count(array_unique(array_column($validated, 'disk'))) !== count($validated)) {
            throw new RuntimeException('File manifest contains duplicate persistent disks.');
        }

        return $validated;
    }

    /** @param list<array<string, mixed>> $manifestStores */
    public function inspect(string $archivePath, array $manifestStores): void
    {
        $stores = $this->validateManifestStores($manifestStores);
        $stats = $this->archiveStats($archivePath, $stores);
        $this->assertStats($stores, $stats);
    }

    /** @param list<array<string, mixed>> $manifestStores */
    public function restore(string $archivePath, array $manifestStores): void
    {
        $stores = $this->validateManifestStores($manifestStores);
        $this->inspect($archivePath, $manifestStores);
        $restoreId = Str::uuid()->toString();
        $stages = [];
        $rollbacks = [];
        $activated = [];

        try {
            foreach ($stores as $store) {
                $parent = dirname($store['root']);
                $this->ensureDirectory($parent);
                $stage = $parent.DIRECTORY_SEPARATOR.'.'.basename($store['root']).'.restore-'.$restoreId;
                $rollback = $parent.DIRECTORY_SEPARATOR.'.'.basename($store['root']).'.rollback-'.$restoreId;
                $this->removeDirectory($stage);
                $this->removeDirectory($rollback);
                $this->ensureDirectory($stage);
                $stages[$store['disk']] = $stage;
                $rollbacks[$store['disk']] = $rollback;
            }

            $this->extractToStages($archivePath, $stores, $stages);

            foreach ($stores as $store) {
                $stats = $this->directoryStats($stages[$store['disk']]);
                $this->assertStoreStats($store, $stats);
            }

            foreach ($stores as $store) {
                $disk = $store['disk'];
                $root = $store['root'];
                $rollback = $rollbacks[$disk];

                if (file_exists($root) && ! rename($root, $rollback)) {
                    throw new RuntimeException("Could not preserve the existing '{$disk}' root before restore.");
                }

                try {
                    if (! rename($stages[$disk], $root)) {
                        throw new RuntimeException("Could not activate restored files for '{$disk}'.");
                    }
                } catch (\Throwable $exception) {
                    if (is_dir($rollback) && ! file_exists($root)) {
                        rename($rollback, $root);
                    }
                    throw $exception;
                }

                $activated[] = $disk;
            }

            foreach ($rollbacks as $rollback) {
                $this->removeDirectory($rollback);
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($activated) as $disk) {
                $store = collect($stores)->firstWhere('disk', $disk);
                if (is_array($store)) {
                    $this->removeDirectory($store['root']);
                    if (is_dir($rollbacks[$disk])) {
                        rename($rollbacks[$disk], $store['root']);
                    }
                }
            }
            foreach ($stages as $stage) {
                $this->removeDirectory($stage);
            }
            throw $exception;
        }
    }

    /** @return list<array{disk: string, root: string, archive_root: string}> */
    public function configuredStores(): array
    {
        $names = config('backup.files.disks');
        if (! is_array($names) || $names === []) {
            throw new RuntimeException('No persistent backup disks are configured.');
        }

        $stores = [];
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('Persistent backup disk names must be non-empty strings.');
            }
            $disk = config("filesystems.disks.{$name}");
            if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local' || ! is_string($disk['root'] ?? null)) {
                throw new RuntimeException("Persistent disk '{$name}' must use a configured local root.");
            }
            $root = $this->absolutePath($disk['root']);
            $stores[] = ['disk' => $name, 'root' => $root, 'archive_root' => 'stores/'.$name];
        }

        if (count(array_unique(array_column($stores, 'root'))) !== count($stores)) {
            throw new RuntimeException('Persistent backup disks must have distinct roots.');
        }

        return $stores;
    }

    public function assertOutputSeparated(string $temporaryRoot): void
    {
        $paths = [$this->absolutePath($temporaryRoot)];
        $backupDisk = config('filesystems.disks.'.config('backup.disk'));
        if (is_array($backupDisk) && ($backupDisk['driver'] ?? null) === 'local' && is_string($backupDisk['root'] ?? null)) {
            $paths[] = $this->absolutePath($backupDisk['root'].DIRECTORY_SEPARATOR.config('backup.path'));
        }

        foreach ($this->configuredStores() as $store) {
            foreach ($paths as $path) {
                if ($this->isWithin($path, $store['root']) || $this->isWithin($store['root'], $path)) {
                    throw new RuntimeException("Backup output '{$path}' overlaps persistent disk '{$store['disk']}'.");
                }
            }
        }
    }

    /** @return array<string, string> */
    private function filesIn(string $root): array
    {
        if (! is_dir($root)) {
            $this->ensureDirectory($root);
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException("Symbolic links are not supported in persistent backup roots: {$file->getPathname()}");
            }
            if (! $file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', $iterator->getSubPathname());
            $this->assertSafeRelativePath($relative);
            $files[$relative] = $file->getPathname();
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /** @param list<array<string, mixed>> $stores */
    private function archiveStats(string $archivePath, array $stores): array
    {
        if (! is_file($archivePath)) {
            throw new RuntimeException('Persistent files archive is missing.');
        }
        $stats = [];
        foreach ($stores as $store) {
            $stats[$store['disk']] = ['file_count' => 0, 'total_bytes' => 0, 'fingerprints' => []];
        }
        $archive = new PharData($archivePath);
        $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            $path = $this->archiveRelativePath($entry->getPathname(), $archivePath);
            [$store, $relative] = $this->matchStorePath($path, $stores);
            $size = $entry->getSize();
            $hash = hash_file('sha256', $entry->getPathname());
            if (! is_string($hash)) {
                throw new RuntimeException("Could not hash archive entry '{$path}'.");
            }
            $stats[$store['disk']]['file_count']++;
            $stats[$store['disk']]['total_bytes'] += $size;
            $stats[$store['disk']]['fingerprints'][$relative] = $this->fingerprint($relative, $size, $hash);
        }

        return $stats;
    }

    /** @param list<array<string, mixed>> $stores @param array<string, string> $stages */
    private function extractToStages(string $archivePath, array $stores, array $stages): void
    {
        $archive = new PharData($archivePath);
        $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            $path = $this->archiveRelativePath($entry->getPathname(), $archivePath);
            [$store, $relative] = $this->matchStorePath($path, $stores);
            $destination = $stages[$store['disk']].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $this->ensureDirectory(dirname($destination));
            $source = fopen($entry->getPathname(), 'rb');
            $target = fopen($destination, 'xb');
            if (! is_resource($source) || ! is_resource($target)) {
                throw new RuntimeException("Could not extract archive entry '{$path}'.");
            }
            try {
                if (stream_copy_to_stream($source, $target) === false) {
                    throw new RuntimeException("Could not extract archive entry '{$path}'.");
                }
            } finally {
                fclose($source);
                fclose($target);
            }
        }
    }

    /** @param list<array<string, mixed>> $stores */
    private function matchStorePath(string $path, array $stores): array
    {
        $this->assertSafeRelativePath($path);
        foreach ($stores as $store) {
            $prefix = $store['archive_root'].'/';
            if (str_starts_with($path, $prefix)) {
                $relative = substr($path, strlen($prefix));
                $this->assertSafeRelativePath($relative);

                return [$store, $relative];
            }
        }
        throw new RuntimeException("Archive entry is outside the declared persistent stores: {$path}");
    }

    private function archiveRelativePath(string $entryPath, string $archivePath): string
    {
        $normalized = str_replace('\\', '/', $entryPath);
        $marker = str_replace('\\', '/', basename($archivePath)).'/';
        $position = strpos($normalized, $marker);
        if ($position === false) {
            throw new RuntimeException('Could not resolve archive entry path.');
        }

        return substr($normalized, $position + strlen($marker));
    }

    private function directoryStats(string $root): array
    {
        $files = $this->filesIn($root);
        $fingerprints = [];
        $bytes = 0;
        foreach ($files as $relative => $absolute) {
            $size = $this->fileSize($absolute);
            $bytes += $size;
            $fingerprints[$relative] = $this->fingerprint($relative, $size, $this->fileHash($absolute));
        }

        return ['file_count' => count($files), 'total_bytes' => $bytes, 'fingerprints' => $fingerprints];
    }

    private function assertStats(array $stores, array $stats): void
    {
        foreach ($stores as $store) {
            $this->assertStoreStats($store, $stats[$store['disk']] ?? []);
        }
    }

    private function assertStoreStats(array $store, array $stats): void
    {
        if (($stats['file_count'] ?? null) !== $store['file_count']
            || ($stats['total_bytes'] ?? null) !== $store['total_bytes']
            || $this->aggregate($stats['fingerprints'] ?? []) !== $store['content_sha256']) {
            throw new RuntimeException("Restored content verification failed for disk '{$store['disk']}'.");
        }
    }

    private function fingerprint(string $path, int $size, string $hash): string
    {
        return $path."\0".$size."\0".$hash."\n";
    }

    private function aggregate(array $fingerprints): string
    {
        ksort($fingerprints, SORT_STRING);

        return hash('sha256', implode('', $fingerprints));
    }

    private function assertSafeRelativePath(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '' || str_contains($normalized, "\0") || str_starts_with($normalized, '/')
            || preg_match('/\A[A-Za-z]:/', $normalized) === 1
            || in_array('..', explode('/', $normalized), true)
            || in_array('.', explode('/', $normalized), true)) {
            throw new RuntimeException("Unsafe archive path: {$path}");
        }
    }

    private function absolutePath(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (! preg_match('/\A(?:[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR, '/').'|'.preg_quote(DIRECTORY_SEPARATOR, '/').')/', $normalized)) {
            $normalized = base_path($normalized);
        }

        return rtrim($normalized, DIRECTORY_SEPARATOR);
    }

    private function isWithin(string $candidate, string $parent): bool
    {
        $candidate = mb_strtolower(rtrim($candidate, DIRECTORY_SEPARATOR));
        $parent = mb_strtolower(rtrim($parent, DIRECTORY_SEPARATOR));

        return $candidate === $parent || str_starts_with($candidate.DIRECTORY_SEPARATOR, $parent.DIRECTORY_SEPARATOR);
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException("Could not create directory '{$path}'.");
        }
    }

    private function fileSize(string $path): int
    {
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException("Could not determine file size for '{$path}'.");
        }

        return $size;
    }

    private function fileHash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw new RuntimeException("Could not hash '{$path}'.");
        }

        return $hash;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
