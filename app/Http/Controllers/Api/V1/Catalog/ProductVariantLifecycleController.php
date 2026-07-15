<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Lifecycle\ArchiveProductVariantAction;
use App\Actions\Catalog\Lifecycle\RestoreProductVariantAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductVariantResource;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductVariantLifecycleController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function archive(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ArchiveProductVariantAction $action,
        ApiResponse $response,
        string $product,
        string $variant,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $this->authorizeVariantArchive($variant);

        $action->execute($this->actor($request), $company, $product, $variant);
        $variant->refresh()->load('product');

        return $response->success(
            (new ProductVariantResource($variant))->resolve($request),
        );
    }

    public function restore(
        Request $request,
        TokenCurrentCompany $currentCompany,
        RestoreProductVariantAction $action,
        ApiResponse $response,
        string $product,
        string $variant,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $this->authorizeVariantRestore($variant);

        $action->execute($this->actor($request), $company, $product, $variant);
        $variant->refresh()->load('product');

        return $response->success(
            (new ProductVariantResource($variant))->resolve($request),
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
