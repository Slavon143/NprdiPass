<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Attributes\SyncVariantAttributeValuesAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\VariantAttributeValueResource;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VariantAttributeController extends Controller
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

        $variant->load([
            'attributeValues.definition',
            'attributeValues.selectedOption',
            'attributeValues.selectedOptions',
        ]);

        return $response->success(
            VariantAttributeValueResource::collection($variant->attributeValues)->resolve($request),
        );
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        SyncVariantAttributeValuesAction $action,
        ApiResponse $response,
        string $product,
        string $variant,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $this->authorizeVariantManageAttributes($variant);

        $validated = $request->validate([
            'attributes' => ['present', 'array', 'max:500'],
        ]);

        /** @var array<string, mixed> $attributes */
        $attributes = $validated['attributes'];

        $action->execute($this->actor($request), $company, $product, $variant, $attributes);
        $variant->refresh()->load([
            'attributeValues.definition',
            'attributeValues.selectedOption',
            'attributeValues.selectedOptions',
        ]);

        return $response->success(
            VariantAttributeValueResource::collection($variant->attributeValues)->resolve($request),
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
