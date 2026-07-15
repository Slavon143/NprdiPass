<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Exceptions\Catalog\MediaOperationException;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Services\Catalog\Media\CatalogMediaStorage;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MediaContentController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function __invoke(
        Request $request,
        TokenCurrentCompany $currentCompany,
        CatalogMediaStorage $storage,
        string $media,
    ): Response {
        $company = $this->currentCompany($currentCompany);
        $media = $this->resolveAnyMedia($company, $media);
        $this->authorizeMediaView($media);

        $etag = '"'.$media->checksum_sha256.'"';

        try {
            $storage->assertReadable($media->storage_path);
        } catch (MediaOperationException $exception) {
            Log::warning('Catalog media content is unavailable via API.', [
                'company_uuid' => $company->uuid,
                'product_uuid' => $media->product()->value('uuid'),
                'variant_uuid' => $media->variant()->value('uuid'),
                'media_uuid' => $media->uuid,
                'operation' => 'api_content',
                'error_code' => $exception->errorCode,
            ]);

            abort(404);
        }

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->stream(function () use ($storage, $media): void {
            $stream = $storage->disk()->readStream($media->storage_path);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $media->mime_type,
            'Content-Length' => (string) $media->size_bytes,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=3600',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
