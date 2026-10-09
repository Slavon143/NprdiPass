<?php

namespace App\Services\Passports\Publication;

use App\Data\Storage\StorageReservation;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Services\Storage\StorageQuotaAdmission;
use App\Services\Storage\TenantStorageLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PublicationAssetStager
{
    /** @var array<string, array{company: Company, reservation: StorageReservation, staging: string, final: string}> */
    private array $quotaAttempts = [];

    /**
     * @param  array<int, array{asset_uuid: string, source_disk: string, source_storage_key: string, file_extension: string}>  $assetManifest
     * @return array<string, array{path: string, checksum: string}> staged data keyed by asset UUID
     */
    public function stageAssets(ProductPassport $passport, string $versionUuid, array $assetManifest): array
    {
        $staged = [];

        try {
            foreach ($assetManifest as $asset) {
                $assetUuid = $asset['asset_uuid'];
                $sourceDisk = $asset['source_disk'];
                $sourceKey = $asset['source_storage_key'];
                $extension = $asset['file_extension'];

                $sourceStorage = Storage::disk($sourceDisk);

                if (! $sourceStorage->exists($sourceKey)) {
                    throw new RuntimeException("Source file not found on disk '{$sourceDisk}': {$sourceKey}");
                }

                $stagingPath = $this->stagingPath($passport, $versionUuid, $assetUuid, $extension);

                $sourceStream = $sourceStorage->readStream($sourceKey);
                if (! is_resource($sourceStream)) {
                    throw new RuntimeException("Failed to read source file: {$sourceKey}");
                }
                $destination = Storage::disk('passport_assets');
                $spool = tmpfile();
                try {
                    if ($spool === false) {
                        throw new RuntimeException('Unable to allocate publication asset spool.');
                    }
                    if (stream_copy_to_stream($sourceStream, $spool) === false || ! rewind($spool)) {
                        throw new RuntimeException("Failed to spool source file: {$sourceKey}");
                    }
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $spool);
                    $checksum = hash_final($hash);
                    if (! rewind($spool)) {
                        throw new RuntimeException("Failed to rewind source file: {$sourceKey}");
                    }
                    $finalPath = $this->finalPath($stagingPath);
                    if ($destination->exists($finalPath)) {
                        throw new RuntimeException("Immutable asset destination already exists: {$finalPath}");
                    }
                    $size = fstat($spool)['size'] ?? null;
                    if (! is_int($size) || $size < 0) {
                        throw new RuntimeException('The publication spool size is unavailable.');
                    }
                    $reservation = app(StorageQuotaAdmission::class)->reserve(
                        $passport->company, 'passport_assets', $finalPath, $size, (string) Str::uuid(),
                    );
                    if ($reservation !== null) {
                        // Local promotion renames one file. Reserve its final
                        // immutable key while these bytes are held in staging.
                        $attempt = ['company' => $passport->company, 'reservation' => $reservation,
                            'staging' => $stagingPath, 'final' => $finalPath];
                        $this->quotaAttempts[$stagingPath] = $attempt;
                        $this->quotaAttempts[$finalPath] = $attempt;
                        if (DB::transactionLevel() > 0) {
                            DB::afterCommit(function () use ($stagingPath, $finalPath): void {
                                unset($this->quotaAttempts[$stagingPath], $this->quotaAttempts[$finalPath]);
                            });
                        }
                    }
                    // Register the attempted key before IO: a failed adapter
                    // write may leave partial bytes behind.
                    $staged[$assetUuid] = ['path' => $stagingPath, 'checksum' => $checksum];
                    if (! $destination->put($stagingPath, $spool)) {
                        throw new RuntimeException("Failed to stage asset file on passport_assets: {$stagingPath}");
                    }
                    if ($this->checksum($stagingPath) !== $checksum) {
                        throw new RuntimeException("Staged asset failed verification: {$stagingPath}");
                    }
                } finally {
                    fclose($sourceStream);
                    if (is_resource($spool)) {
                        fclose($spool);
                    }
                }

                $staged[$assetUuid] = [
                    'path' => $stagingPath,
                    'checksum' => $checksum,
                ];
            }

            return $staged;
        } catch (Throwable $exception) {
            $this->cleanupStaging($staged);

            throw $exception;
        }
    }

    /**
     * @param  array<string, array{path: string, checksum: string}>  $staged
     * @return array<string, array{path: string, checksum: string}> final data keyed by asset UUID
     */
    public function promoteAssets(array $staged, string $versionUuid): array
    {
        $final = [];
        $disk = Storage::disk('passport_assets');

        try {
            foreach ($staged as $assetUuid => $data) {
                $stagingPath = $data['path'];
                $finalPath = $this->finalPath($stagingPath);

                // Immutable final keys are never overwritten: a colliding key
                // means a previous publication already owns this asset name.
                if ($disk->exists($finalPath)) {
                    throw new RuntimeException("Immutable asset destination already exists: {$finalPath}");
                }

                // A remote move may copy bytes before failing to delete the
                // staging object. This attempt owns the new destination key.
                $final[$assetUuid] = ['path' => $finalPath, 'checksum' => $data['checksum']];
                if (! $disk->move($stagingPath, $finalPath)) {
                    throw new RuntimeException("Failed to promote asset file: {$finalPath}");
                }

                if ($this->checksum($finalPath) !== $data['checksum']) {
                    $disk->delete($finalPath);

                    throw new RuntimeException("Promoted asset failed verification: {$finalPath}");
                }

                $this->enforceImmutability($finalPath);
                if (isset($this->quotaAttempts[$finalPath])) {
                    $attempt = $this->quotaAttempts[$finalPath];
                    app(StorageQuotaAdmission::class)->written($attempt['company'], $attempt['reservation']);
                }

                $final[$assetUuid] = [
                    'path' => $finalPath,
                    'checksum' => $data['checksum'],
                ];
            }

            return $final;
        } catch (Throwable $exception) {
            $this->cleanupPromoted($final);

            throw $exception;
        }
    }

    private function checksum(string $path): string
    {
        $stream = Storage::disk('passport_assets')->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException("Failed to read publication asset: {$path}");
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, array{path: string, checksum: string}>  $staged
     */
    public function cleanupStaging(array $staged): void
    {
        $disk = Storage::disk('passport_assets');

        foreach ($staged as $data) {
            $stagingPath = $data['path'];
            if ($disk->exists($stagingPath)) {
                $disk->delete($stagingPath);
            }
            $this->releaseAbsentAttempt($stagingPath);
        }
    }

    /**
     * @param  array<string, array{path: string, checksum: string}>  $promoted
     */
    public function cleanupPromoted(array $promoted): void
    {
        $disk = Storage::disk('passport_assets');

        foreach ($promoted as $data) {
            $path = $data['path'];
            $this->restoreWritability($path);

            if ($disk->exists($path)) {
                $disk->delete($path);
            }
            $this->releaseAbsentAttempt($path);
        }
    }

    private function releaseAbsentAttempt(string $path): void
    {
        $attempt = $this->quotaAttempts[$path] ?? null;
        if ($attempt === null) {
            return;
        }
        $disk = Storage::disk('passport_assets');
        if ($disk->exists($attempt['staging']) || $disk->exists($attempt['final'])) {
            return;
        }
        app(TenantStorageLedger::class)->releaseConfirmed($attempt['company'], $attempt['reservation']);
        unset($this->quotaAttempts[$attempt['staging']], $this->quotaAttempts[$attempt['final']]);
    }

    private function finalPath(string $stagingPath): string
    {
        $path = preg_replace('#/staging/#', '/media/', $stagingPath, 1);
        if (! is_string($path) || $path === $stagingPath) {
            throw new RuntimeException("Invalid staging path for asset promotion: {$stagingPath}");
        }

        return $path;
    }

    /**
     * Best-effort storage-level immutability: published asset files are made
     * read-only when the backing disk is a local filesystem. Remote backends
     * should provide equivalent protection (e.g. S3 object lock/versioning)
     * at the storage layer. Failures are reported, not fatal: file-level
     * permissions are an extra layer, not the correctness guarantee.
     */
    private function enforceImmutability(string $path): void
    {
        if (! (bool) config('passports.asset_readonly', true)) {
            return;
        }

        if ((string) config('filesystems.disks.passport_assets.driver', 'local') !== 'local') {
            return;
        }

        $fullPath = Storage::disk('passport_assets')->path($path);

        if (! is_file($fullPath) || chmod($fullPath, 0444)) {
            return;
        }

        report(new RuntimeException("Could not make published asset read-only: {$path}"));
    }

    private function restoreWritability(string $path): void
    {
        if ((string) config('filesystems.disks.passport_assets.driver', 'local') !== 'local') {
            return;
        }

        $fullPath = Storage::disk('passport_assets')->path($path);

        if (is_file($fullPath)) {
            @chmod($fullPath, 0644);
        }
    }

    private function stagingPath(ProductPassport $passport, string $versionUuid, string $assetUuid, string $extension): string
    {
        return sprintf(
            'companies/%s/passports/%s/versions/%s/staging/%s.%s',
            $passport->company->uuid,
            $passport->uuid,
            $versionUuid,
            $assetUuid,
            $extension,
        );
    }
}
