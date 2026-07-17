<?php

namespace App\Http\Controllers\Passports;

use App\Enums\Passports\ProductPassportStatus;
use App\Http\Controllers\Controller;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicPassportAssetController extends Controller
{
    public function media(Request $request, string $publicId, string $asset): Response
    {
        $passport = $this->resolvePublishedPassport($publicId);
        $version = $passport->currentPublishedVersion;

        $assetModel = ProductPassportAsset::query()
            ->where('uuid', $asset)
            ->where('passport_id', $passport->getKey())
            ->where('version_id', $version->getKey())
            ->where('is_public', true)
            ->first();

        if ($assetModel === null) {
            throw new NotFoundHttpException('Media not found.');
        }

        $disk = Storage::disk('passport_assets');

        if (! $disk->exists($assetModel->storage_key)) {
            throw new NotFoundHttpException('Media file not found.');
        }

        $etag = '"'.$assetModel->checksum_sha256.'"';
        $mimeType = $assetModel->mime_type;
        $sizeBytes = $assetModel->size_bytes;

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->stream(function () use ($disk, $assetModel): void {
            $stream = $disk->readStream($assetModel->storage_key);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $sizeBytes,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function document(Request $request, string $publicId, string $asset): Response
    {
        $passport = $this->resolvePublishedPassport($publicId);
        $version = $passport->currentPublishedVersion;

        $assetModel = ProductPassportAsset::query()
            ->where('uuid', $asset)
            ->where('passport_id', $passport->getKey())
            ->where('version_id', $version->getKey())
            ->where('is_public', true)
            ->first();

        if ($assetModel === null) {
            throw new NotFoundHttpException('Document not found.');
        }

        $disk = Storage::disk('passport_assets');

        if (! $disk->exists($assetModel->storage_key)) {
            throw new NotFoundHttpException('Document file not found.');
        }

        $etag = '"'.$assetModel->checksum_sha256.'"';
        $mimeType = $assetModel->mime_type;
        $sizeBytes = $assetModel->size_bytes;

        $safeFilename = $this->sanitizeFilename(
            $assetModel->uuid.'.'.$assetModel->file_extension,
        );

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->stream(function () use ($disk, $assetModel): void {
            $stream = $disk->readStream($assetModel->storage_key);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $sizeBytes,
            'Content-Disposition' => 'attachment; filename="'.$safeFilename.'"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function resolvePublishedPassport(string $publicId): ProductPassport
    {
        $passport = ProductPassport::query()
            ->with('currentPublishedVersion')
            ->where('public_id', $publicId)
            ->first();

        if ($passport === null) {
            throw new NotFoundHttpException('Passport not found.');
        }

        if ($passport->status !== ProductPassportStatus::Published) {
            throw new NotFoundHttpException('Passport is not published.');
        }

        if ($passport->currentPublishedVersion === null) {
            throw new NotFoundHttpException('No published version found.');
        }

        return $passport;
    }

    private function sanitizeFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        $name = (string) preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        $ext = (string) preg_replace('/[^a-zA-Z0-9]/', '', $ext);

        if ($ext !== '') {
            return $name.'.'.$ext;
        }

        return $name;
    }
}
