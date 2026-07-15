<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Media\DeleteProductMediaAction;
use App\Actions\Catalog\Media\ReorderProductMediaAction;
use App\Actions\Catalog\Media\SetPrimaryProductMediaAction;
use App\Actions\Catalog\Media\UpdateProductMediaAction;
use App\Actions\Catalog\Media\UploadProductMediaAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductMediaResource;
use App\Models\Catalog\ProductMedia;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductMediaController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function index(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductView($product);

        $media = ProductMedia::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->productLevel()
            ->ordered()
            ->with('uploadedBy:id,name')
            ->get();

        $media->each(fn (ProductMedia $item) => $item->setRelation('product', $product));

        return $response->success(
            ProductMediaResource::collection($media)->resolve($request),
        );
    }

    public function store(
        Request $request,
        TokenCurrentCompany $currentCompany,
        UploadProductMediaAction $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductMediaManage($product);

        $validated = $request->validate([
            'image' => ['required', 'file', 'max:'.config('catalog.media.max_file_size_kb')],
            'alt_text' => ['nullable', 'string', 'max:'.config('catalog.media.alt_text_max')],
            'caption' => ['nullable', 'string', 'max:'.config('catalog.media.caption_max')],
            'make_primary' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $media = $action->execute(
            $this->actor($request),
            $company,
            $product,
            $request->file('image'),
            $validated['alt_text'] ?? null,
            $validated['caption'] ?? null,
            (bool) ($validated['make_primary'] ?? false),
            $validated['sort_order'] ?? null,
        );

        $media->load('product');

        return $response->created(
            (new ProductMediaResource($media))->resolve($request),
        );
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        UpdateProductMediaAction $action,
        ApiResponse $response,
        string $product,
        string $media,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $media = $this->resolveProductMedia($company, $product, $media);
        $this->authorizeMediaView($media);

        $validated = $request->validate([
            'alt_text' => ['nullable', 'string', 'max:'.config('catalog.media.alt_text_max')],
            'caption' => ['nullable', 'string', 'max:'.config('catalog.media.caption_max')],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $action->executeProduct($this->actor($request), $company, $product, $media, $validated);
        $media->refresh()->load('product');

        return $response->success(
            (new ProductMediaResource($media))->resolve($request),
        );
    }

    public function setPrimary(
        Request $request,
        TokenCurrentCompany $currentCompany,
        SetPrimaryProductMediaAction $action,
        ApiResponse $response,
        string $product,
        string $media,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $media = $this->resolveProductMedia($company, $product, $media);
        $this->authorizeMediaSetPrimary($media);

        $action->execute($this->actor($request), $company, $product, $media);
        $media->refresh()->load('product');

        return $response->success(
            (new ProductMediaResource($media))->resolve($request),
        );
    }

    public function reorder(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ReorderProductMediaAction $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductMediaManage($product);

        $validated = $request->validate([
            'media_uuids' => ['required', 'array', 'min:1', 'max:50'],
            'media_uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        /** @var list<string> $uuids */
        $uuids = array_values($validated['media_uuids']);
        $action->execute($this->actor($request), $company, $product, $uuids);

        $media = ProductMedia::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->productLevel()
            ->ordered()
            ->get();

        $media->each(fn (ProductMedia $item) => $item->setRelation('product', $product));

        return $response->success(
            ProductMediaResource::collection($media)->resolve($request),
        );
    }

    public function destroy(
        Request $request,
        TokenCurrentCompany $currentCompany,
        DeleteProductMediaAction $action,
        ApiResponse $response,
        string $product,
        string $media,
    ): Response {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $media = $this->resolveProductMedia($company, $product, $media);
        $this->authorizeMediaDelete($media);

        $action->execute($this->actor($request), $company, $product, $media);

        return $response->noContent();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
