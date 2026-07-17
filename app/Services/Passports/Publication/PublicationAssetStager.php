<?php

namespace App\Services\Passports\Publication;

use App\Models\Passports\ProductPassport;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PublicationAssetStager
{
    /**
     * @param  array<int, array{asset_uuid: string, source_disk: string, source_storage_key: string, file_extension: string, checksum_sha256: string}>  $assetManifest
     * @return array<string, string> staged paths keyed by asset UUID
     */
    public function stageAssets(ProductPassport $passport, string $versionUuid, array $assetManifest): array
    {
        $stagedPaths = [];

        foreach ($assetManifest as $asset) {
            $assetUuid = $asset['asset_uuid'];
            $sourceDisk = $asset['source_disk'];
            $sourceKey = $asset['source_storage_key'];
            $extension = $asset['file_extension'];
            $expectedChecksum = $asset['checksum_sha256'];

            $sourceStorage = Storage::disk($sourceDisk);

            if (! $sourceStorage->exists($sourceKey)) {
                throw new RuntimeException("Source file not found on disk '{$sourceDisk}': {$sourceKey}");
            }

            $stagingPath = $this->stagingPath($passport, $versionUuid, $assetUuid, $extension);

            $sourceContent = $sourceStorage->get($sourceKey);

            if ($sourceContent === null) {
                throw new RuntimeException("Failed to read source file: {$sourceKey}");
            }

            $actualChecksum = hash('sha256', $sourceContent);

            if ($actualChecksum !== $expectedChecksum) {
                throw new RuntimeException(
                    "Checksum mismatch for asset {$assetUuid}: expected {$expectedChecksum}, got {$actualChecksum}",
                );
            }

            Storage::disk('passport_assets')->put($stagingPath, $sourceContent);

            $stagedPaths[$assetUuid] = $stagingPath;
        }

        return $stagedPaths;
    }

    /**
     * @param  array<string, string>  $stagedPaths
     * @return array<string, string> final paths keyed by asset UUID
     */
    public function promoteAssets(array $stagedPaths, string $versionUuid): array
    {
        $finalPaths = [];
        $disk = Storage::disk('passport_assets');

        foreach ($stagedPaths as $assetUuid => $stagingPath) {
            $finalPath = preg_replace(
                '#/staging/#',
                '/media/',
                $stagingPath,
                1,
            );

            $disk->move($stagingPath, $finalPath);

            $finalPaths[$assetUuid] = $finalPath;
        }

        return $finalPaths;
    }

    /**
     * @param  array<string, string>  $stagedPaths
     */
    public function cleanupStaging(array $stagedPaths): void
    {
        $disk = Storage::disk('passport_assets');

        foreach ($stagedPaths as $stagingPath) {
            if ($disk->exists($stagingPath)) {
                $disk->delete($stagingPath);
            }
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
