<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Lifecycle\ActivateProductAction;
use App\Actions\Catalog\Lifecycle\ArchiveProductAction;
use App\Actions\Catalog\Lifecycle\RestoreProductAction;
use App\Actions\Catalog\Lifecycle\ReturnProductToDraftAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductResource;
use App\Http\Resources\Catalog\ReadinessResource;
use App\Models\User;
use App\Services\Catalog\ProductActivationReadinessService;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductLifecycleController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function readiness(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ProductActivationReadinessService $readinessService,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductViewReadiness($product);

        $readiness = $readinessService->evaluate($company, $product);

        return $response->success(
            (new ReadinessResource($readiness))->resolve($request),
        );
    }

    public function activate(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ActivateProductAction $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductActivate($product);

        $updated = $action->execute($this->actor($request), $company, $product);
        $updated->load(['primaryCategory', 'categories', 'defaultVariant']);

        return $response->success(
            (new ProductResource($updated))->resolve($request),
        );
    }

    public function returnToDraft(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ReturnProductToDraftAction $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductReturnToDraft($product);

        $updated = $action->execute($this->actor($request), $company, $product);
        $updated->load(['primaryCategory', 'categories', 'defaultVariant']);

        return $response->success(
            (new ProductResource($updated))->resolve($request),
        );
    }

    public function archive(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ArchiveProductAction $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductArchive($product);

        $updated = $action->execute($this->actor($request), $company, $product);
        $updated->load(['primaryCategory', 'categories', 'defaultVariant']);

        return $response->success(
            (new ProductResource($updated))->resolve($request),
        );
    }

    public function restore(
        Request $request,
        TokenCurrentCompany $currentCompany,
        RestoreProductAction $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductRestore($product);

        $updated = $action->execute($this->actor($request), $company, $product);
        $updated->load(['primaryCategory', 'categories', 'defaultVariant']);

        return $response->success(
            (new ProductResource($updated))->resolve($request),
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
