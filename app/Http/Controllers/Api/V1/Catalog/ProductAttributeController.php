<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Attributes\SyncProductAttributeValuesAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductAttributeValueResource;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductAttributeController extends Controller
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

        $product->load([
            'attributeValues.definition',
            'attributeValues.selectedOption',
            'attributeValues.selectedOptions',
        ]);

        return $response->success(
            ProductAttributeValueResource::collection($product->attributeValues)->resolve($request),
        );
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        SyncProductAttributeValuesAction $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductManageAttributes($product);

        $validated = $request->validate([
            'attributes' => ['present', 'array', 'max:500'],
        ]);

        /** @var array<string, mixed> $attributes */
        $attributes = $validated['attributes'];

        $action->execute($this->actor($request), $company, $product, $attributes);
        $product->refresh()->load([
            'attributeValues.definition',
            'attributeValues.selectedOption',
            'attributeValues.selectedOptions',
        ]);

        return $response->success(
            ProductAttributeValueResource::collection($product->attributeValues)->resolve($request),
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
