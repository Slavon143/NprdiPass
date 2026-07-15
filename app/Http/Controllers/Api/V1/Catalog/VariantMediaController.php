<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Media\DeleteVariantMediaAction;
use App\Actions\Catalog\Media\ReorderVariantMediaAction;
use App\Actions\Catalog\Media\SetPrimaryVariantMediaAction;
use App\Actions\Catalog\Media\UpdateProductMediaAction;
use App\Actions\Catalog\Media\UploadVariantMediaAction;
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

class VariantMediaController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function index(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $product,
        string $variant,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $this->authorizeProductView($product);

        $media = ProductMedia::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->ordered()
            ->with('uploadedBy:id,name')
            ->get();

        $media->each(fn (ProductMedia $item) => $item->setRelation('variant', $variant));

        return $response->success(
            ProductMediaResource::collection($media)->resolve($request),
        );
    }

    public function store(
        Request $request,
        TokenCurrentCompany $currentCompany,
        UploadVariantMediaAction $action,
        ApiResponse $response,
        string $product,
        string $variant,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
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
            $variant,
            $request->file('image'),
            $validated['alt_text'] ?? null,
            $validated['caption'] ?? null,
            (bool) ($validated['make_primary'] ?? false),
            $validated['sort_order'] ?? null,
        );

        $media->load('variant');

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
        string $variant,
        string $media,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $media = $this->resolveVariantMedia($company, $product, $variant, $media);
        $this->authorizeMediaView($media);

        $validated = $request->validate([
            'alt_text' => ['nullable', 'string', 'max:'.config('catalog.media.alt_text_max')],
            'caption' => ['nullable', 'string', 'max:'.config('catalog.media.caption_max')],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $action->executeVariant($this->actor($request), $company, $product, $variant, $media, $validated);
        $media->refresh()->load('variant');

        return $response->success(
            (new ProductMediaResource($media))->resolve($request),
        );
    }

    public function setPrimary(
        Request $request,
        TokenCurrentCompany $currentCompany,
        SetPrimaryVariantMediaAction $action,
        ApiResponse $response,
        string $product,
        string $variant,
        string $media,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $media = $this->resolveVariantMedia($company, $product, $variant, $media);
        $this->authorizeMediaSetPrimary($media);

        $action->execute($this->actor($request), $company, $product, $variant, $media);
        $media->refresh()->load('variant');

        return $response->success(
            (new ProductMediaResource($media))->resolve($request),
        );
    }

    public function reorder(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ReorderVariantMediaAction $action,
        ApiResponse $response,
        string $product,
        string $variant,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $this->authorizeProductMediaManage($product);

        $validated = $request->validate([
            'media_uuids' => ['required', 'array', 'min:1', 'max:10'],
            'media_uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        /** @var list<string> $uuids */
        $uuids = array_values($validated['media_uuids']);
        $action->execute($this->actor($request), $company, $product, $variant, $uuids);

        $media = ProductMedia::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->ordered()
            ->get();

        $media->each(fn (ProductMedia $item) => $item->setRelation('variant', $variant));

        return $response->success(
            ProductMediaResource::collection($media)->resolve($request),
        );
    }

    public function destroy(
        Request $request,
        TokenCurrentCompany $currentCompany,
        DeleteVariantMediaAction $action,
        ApiResponse $response,
        string $product,
        string $variant,
        string $media,
    ): Response {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $media = $this->resolveVariantMedia($company, $product, $variant, $media);
        $this->authorizeMediaDelete($media);

        $action->execute($this->actor($request), $company, $product, $variant, $media);

        return $response->noContent();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
