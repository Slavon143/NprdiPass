<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Data\Catalog\Search\CatalogProductSearchCriteria;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductResource;
use App\Http\Resources\Catalog\ProductSummaryResource;
use App\Models\User;
use App\Queries\Catalog\ProductCatalogQuery;
use App\Services\Catalog\CategoryHierarchyService;
use App\Support\Catalog\Search\CatalogSearchStringNormalizer;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function index(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ProductCatalogQuery $catalogQuery,
        CategoryHierarchyService $hierarchy,
        CatalogSearchStringNormalizer $searchNormalizer,
        ApiResponse $response,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $this->authorizeProductViewAny($company);

        $criteria = new CatalogProductSearchCriteria(
            query: $searchNormalizer->normalize((string) $request->query('q', '')),
            productStatuses: (array) $request->query('product_statuses', ['draft', 'active']),
            variantStatuses: (array) $request->query('variant_statuses', []),
            categoryIds: [],
            categoryUuids: (array) $request->query('category_uuids', []),
            categoryMode: (string) $request->query('category_mode', 'primary'),
            includeDescendants: $request->boolean('include_descendants'),
            brand: $request->query('brand'),
            manufacturer: $request->query('manufacturer'),
            readiness: (string) $request->query('readiness', 'any'),
            missingData: (array) $request->query('missing_data', []),
            passportStatuses: (array) $request->query('passport_statuses', []),
            needsAttention: $request->boolean('needs_attention'),
            attributeFilters: [],
            sort: (string) $request->query('sort', 'updated'),
            direction: (string) $request->query('direction', 'desc'),
            perPage: min((int) $request->query('per_page', 25), 100),
        );

        $paginator = $catalogQuery->build($company, $criteria)->paginate($criteria->perPage);

        return $response->paginated(
            ProductSummaryResource::collection($paginator)->resolve($request),
            $paginator,
        );
    }

    public function store(
        Request $request,
        TokenCurrentCompany $currentCompany,
        CreateProductAction $action,
        ApiResponse $response,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $this->authorizeProductCreate($company);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'brand' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'primary_category_uuid' => ['nullable', 'uuid'],
            'category_uuids' => ['nullable', 'array', 'max:20'],
            'category_uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $primaryCategoryUuid = $this->nullableString($validated, 'primary_category_uuid');
        /** @var list<string> $categoryUuids */
        $categoryUuids = isset($validated['category_uuids']) && is_array($validated['category_uuids'])
            ? array_values($validated['category_uuids'])
            : [];

        $product = $action->execute(
            $this->actor($request),
            $company,
            $validated,
            $primaryCategoryUuid,
            $categoryUuids,
        );

        $product->load(['primaryCategory', 'categories', 'defaultVariant'])->loadCount('variants');

        return $response->created(
            (new ProductResource($product))->resolve($request),
        );
    }

    public function show(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductView($product);

        $product->load([
            'primaryCategory',
            'categories' => fn ($query) => $query->ordered(),
            'defaultVariant',
            'variants' => fn ($query) => $query->ordered()->limit(5),
            'createdBy',
            'updatedBy',
            'primaryMedia',
            'attributeValues.definition',
            'attributeValues.selectedOption',
            'attributeValues.selectedOptions',
        ])->loadCount(['variants', 'productMedia']);

        return $response->success(
            (new ProductResource($product))->resolve($request),
        );
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        UpdateProductAction $action,
        ApiResponse $response,
        string $product,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $product = $this->resolveProduct($company, $product);
        $this->authorizeProductUpdate($product);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'brand' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'primary_category_uuid' => ['nullable', 'uuid'],
            'category_uuids' => ['nullable', 'array', 'max:20'],
            'category_uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $primaryCategoryUuid = $this->nullableString($validated, 'primary_category_uuid');
        /** @var list<string> $categoryUuids */
        $categoryUuids = isset($validated['category_uuids']) && is_array($validated['category_uuids'])
            ? array_values($validated['category_uuids'])
            : [];

        $product = $action->execute(
            $this->actor($request),
            $company,
            $product,
            $validated,
            $primaryCategoryUuid,
            $categoryUuids,
        );

        $product->load(['primaryCategory', 'categories', 'defaultVariant']);

        return $response->success(
            (new ProductResource($product))->resolve($request),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
