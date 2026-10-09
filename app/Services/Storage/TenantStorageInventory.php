<?php

namespace App\Services\Storage;

use App\Models\Company;
use App\Services\Catalog\Documents\DocumentFileStorage;
use App\Services\Catalog\Media\CatalogMediaStorage;
use Generator;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FileAttributes;
use RuntimeException;

class TenantStorageInventory
{
    /** @return array{files: int, bytes: int} */
    public function measure(Company $company): array
    {
        $files = 0;
        $bytes = 0;
        foreach ($this->objects($company) as $object) {
            if ($bytes > PHP_INT_MAX - $object['bytes']) {
                throw new RuntimeException('Storage inventory size is invalid.');
            }
            $files++;
            $bytes += $object['bytes'];
        }

        return compact('files', 'bytes');
    }

    /** @return Generator<int, array{disk: string, key: string, physical_key: string, bytes: int}> */
    public function objects(Company $company): Generator
    {
        $locations = $this->locations($company);
        usort($locations, fn ($left, $right) => strlen($left['directory']) <=> strlen($right['directory']));
        $scanned = [];
        foreach ($locations as $location) {
            foreach ($scanned as $previous) {
                if ($previous['backend'] === $location['backend'] && str_starts_with($location['directory'], $previous['directory'])) {
                    continue 2;
                }
            }
            foreach ($location['disk']->getDriver()->listContents($location['prefix'], true) as $entry) {
                if (! $entry->isFile()) {
                    continue;
                }
                if (! $entry instanceof FileAttributes || ! str_starts_with($entry->path(), $location['prefix'])) {
                    throw new RuntimeException('Storage inventory escaped the tenant prefix or returned unsupported metadata.');
                }
                $size = $entry->fileSize() ?? $location['disk']->size($entry->path());
                if ($size < 0) {
                    throw new RuntimeException('Storage inventory size is invalid.');
                }
                yield ['disk' => $location['name'], 'key' => $entry->path(),
                    'physical_key' => $this->identity($location, $entry->path()), 'bytes' => $size];
            }
            $scanned[] = $location;
        }
    }

    public function physicalKey(Company $company, string $disk, string $key): string
    {
        if (str_starts_with($key, '/') || str_contains($key, '\\') || str_contains($key, "\0")
            || str_contains($key, '//') || preg_match('#(^|/)\.\.?(/|$)#', $key) === 1) {
            throw new RuntimeException('Unsafe physical storage key.');
        }
        foreach ($this->locations($company) as $location) {
            if ($location['name'] === $disk && str_starts_with($key, $location['prefix'])) {
                return $this->identity($location, $key);
            }
        }
        throw new RuntimeException('Storage key is outside the selected tenant locations.');
    }

    /** @return array<int, array{name: string, disk: FilesystemAdapter, prefix: string, backend: string, directory: string}> */
    private function locations(Company $company): array
    {
        if (! $company->exists || ! Str::isUuid($company->uuid)) {
            throw new RuntimeException('A persisted tenant identity is required for storage inventory.');
        }
        $locations = [
            [app(CatalogMediaStorage::class)->diskName(), $company->uuid.'/'],
            [app(DocumentFileStorage::class)->diskName(), 'companies/'.$company->uuid.'/'],
            [(string) config('imports.disk'), 'companies/'.$company->uuid.'/'],
            ['passport_assets', 'companies/'.$company->uuid.'/'],
        ];
        $physical = [];
        foreach ($locations as [$name, $prefix]) {
            $disk = Storage::disk($name);
            $config = (array) config('filesystems.disks.'.$name);
            if (($config['driver'] ?? null) === 'local') {
                $root = realpath($disk->path(''));
                if ($root === false) {
                    throw new RuntimeException('Storage inventory root cannot be resolved.');
                }
                $backend = 'local';
                $directory = str_replace('\\', '/', $root).'/'.$prefix;
                if (PHP_OS_FAMILY === 'Windows') {
                    $directory = strtolower($directory);
                }
            } elseif (($config['driver'] ?? null) === 's3') {
                $backend = 's3:'.hash('sha256', (string) ($config['endpoint'] ?? '').'|'.(string) ($config['bucket'] ?? ''));
                $directory = trim((string) ($config['root'] ?? ''), '/').'/'.$prefix;
            } else {
                throw new RuntimeException('Storage inventory backend is not supported.');
            }
            $physical[] = compact('name', 'disk', 'prefix', 'backend', 'directory');
        }

        return $physical;
    }

    private function identity(array $location, string $key): string
    {
        $physical = $location['directory'].substr($key, strlen($location['prefix']));
        if ($location['backend'] === 'local' && PHP_OS_FAMILY === 'Windows') {
            $physical = strtolower($physical);
        }

        return hash('sha256', $location['backend'].'|'.$physical);
    }
}
