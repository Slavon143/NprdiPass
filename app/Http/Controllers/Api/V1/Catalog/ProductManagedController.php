<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Products\SetProductManagedAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductResource;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductManagedController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function update(Request $request, TokenCurrentCompany $currentCompany, SetProductManagedAction $action, ApiResponse $response, string $product): JsonResponse
    {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductUpdate($product);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validate(['managed' => ['required', 'boolean']]);
        $updated = $action->execute($actor, $company, $product, (bool) $data['managed']);

        return $response->success((new ProductResource($updated))->resolve($request));
    }
}
