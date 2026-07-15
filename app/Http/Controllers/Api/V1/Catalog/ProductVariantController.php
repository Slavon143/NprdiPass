<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\SetDefaultProductVariantAction;
use App\Actions\Catalog\Variants\CreateProductVariantAction;
use App\Actions\Catalog\Variants\UpdateProductVariantAction;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductVariantResource;
use App\Models\Catalog\ProductVariant;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductVariantController extends Controller
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

        $product->load('defaultVariant');

        $paginator = ProductVariant::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->ordered()
            ->paginate(25);

        $paginator->each(fn (ProductVariant $variant) => $variant->setRelation('product', $product));

        return $response->paginated(
            ProductVariantResource::collection($paginator)->resolve($request),
            $paginator,
        );
    }

    public function store(
        Request $request,
        TokenCurrentCompany $currentCompany,
        CreateProductVariantAction $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeVariantCreate($product);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'gtin' => ['nullable', 'string', 'max:14'],
            'manufacturer_part_number' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $data = ['name' => $validated['name'] ?? null];

        if (array_key_exists('sku', $validated)) {
            $data['sku'] = $validated['sku'];
        }

        if (array_key_exists('gtin', $validated)) {
            $data['gtin'] = $validated['gtin'];
        }

        if (array_key_exists('manufacturer_part_number', $validated)) {
            $data['manufacturer_part_number'] = $validated['manufacturer_part_number'];
        }

        if (array_key_exists('sort_order', $validated)) {
            $data['sort_order'] = $validated['sort_order'];
        }

        $variant = $action->execute($this->actor($request), $company, $product, $data);
        $variant->load('product');

        return $response->created(
            (new ProductVariantResource($variant))->resolve($request),
        );
    }

    public function show(
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

        $product->load(['defaultVariant', 'primaryMedia']);
        $variant->load([
            'primaryMedia',
            'createdBy',
            'updatedBy',
            'attributeValues.definition',
            'attributeValues.selectedOption',
            'attributeValues.selectedOptions',
        ])->loadCount('media');
        $variant->setRelation('product', $product);

        return $response->success(
            (new ProductVariantResource($variant))->resolve($request),
        );
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        UpdateProductVariantAction $action,
        ApiResponse $response,
        string $product,
        string $variant,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $this->authorizeVariantUpdate($variant);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'gtin' => ['nullable', 'string', 'max:14'],
            'manufacturer_part_number' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $data = [];

        foreach (['name', 'sku', 'gtin', 'manufacturer_part_number', 'sort_order'] as $field) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = $validated[$field];
            }
        }

        $variant = $action->execute($this->actor($request), $company, $product, $variant, $data);
        $variant->load('product');

        return $response->success(
            (new ProductVariantResource($variant))->resolve($request),
        );
    }

    public function setDefault(
        Request $request,
        TokenCurrentCompany $currentCompany,
        SetDefaultProductVariantAction $action,
        ApiResponse $response,
        string $product,
        string $variant,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $this->authorizeVariantSetDefault($variant);

        $action->execute($this->actor($request), $product, $variant);
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
