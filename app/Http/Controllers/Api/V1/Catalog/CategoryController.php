<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Catalog\Categories\ArchiveCategoryAction;
use App\Actions\Catalog\Categories\CreateCategoryAction;
use App\Actions\Catalog\Categories\MoveCategoryAction;
use App\Actions\Catalog\Categories\ReorderSiblingCategoriesAction;
use App\Actions\Catalog\Categories\RestoreCategoryAction;
use App\Actions\Catalog\Categories\UpdateCategoryAction;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\V1\Catalog\Concerns\AuthorizesCatalogApi;
use App\Http\Controllers\Api\V1\Catalog\Concerns\ResolvesCatalogApiResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\CategoryResource;
use App\Models\Catalog\Category;
use App\Models\User;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use AuthorizesCatalogApi;
    use ResolvesCatalogApiResources;

    public function index(Request $request, TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $company = $this->currentCompany($currentCompany);
        $this->authorizeCategoryViewAny($company);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:active,archived,all'],
            'parent_uuid' => ['nullable', 'string', 'max:36'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        $query = Category::query()
            ->forCompany($company)
            ->with('parent:id,uuid,name')
            ->withCount([
                'children as active_children_count' => fn (Builder $query): Builder => $query
                    ->where('status', CategoryStatus::Active->value),
                'primaryProducts as active_primary_products_count' => fn (Builder $query): Builder => $query
                    ->where('status', ProductStatus::Active->value),
            ]);

        $status = $filters['status'] ?? 'all';

        if ($status === CategoryStatus::Active->value) {
            $query->active();
        } elseif ($status === CategoryStatus::Archived->value) {
            $query->archived();
        }

        $parentFilter = $filters['parent_uuid'] ?? null;

        if ($parentFilter === 'root') {
            $query->roots();
        } elseif (is_string($parentFilter) && $parentFilter !== '') {
            $parent = $this->resolveCategory($company, $parentFilter);
            $query->where('parent_id', $parent->getKey());
        }

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) ($filters['per_page'] ?? 50), 100);
        $paginator = $query->ordered()->paginate($perPage);

        return $response->paginated(
            CategoryResource::collection($paginator)->resolve($request),
            $paginator,
        );
    }

    public function store(
        Request $request,
        TokenCurrentCompany $currentCompany,
        CreateCategoryAction $action,
        ApiResponse $response,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $this->authorizeCategoryManage($company);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_uuid' => ['nullable', 'uuid'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $parent = null;
        $parentUuid = $validated['parent_uuid'] ?? null;

        if (is_string($parentUuid) && $parentUuid !== '') {
            $parent = $this->resolveCategory($company, $parentUuid);
        }

        $category = $action->execute($this->actor($request), $company, $validated, $parent);

        return $response->created(
            (new CategoryResource($category))->resolve($request),
        );
    }

    public function show(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $category,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $category = $this->resolveCategory($company, $category);
        $this->authorizeCategoryView($category);

        $category->load([
            'parent',
            'createdBy',
            'updatedBy',
        ])->loadCount([
            'children as active_children_count' => fn (Builder $query): Builder => $query
                ->where('status', CategoryStatus::Active->value),
            'primaryProducts as active_primary_products_count' => fn (Builder $query): Builder => $query
                ->where('status', ProductStatus::Active->value),
        ]);

        return $response->success(
            (new CategoryResource($category))->resolve($request),
        );
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        UpdateCategoryAction $action,
        ApiResponse $response,
        string $category,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $category = $this->resolveCategory($company, $category);
        $this->authorizeCategoryUpdate($category);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ]);

        $action->execute($this->actor($request), $company, $category, $validated);
        $category->refresh()->load('parent');

        return $response->success(
            (new CategoryResource($category))->resolve($request),
        );
    }

    public function move(
        Request $request,
        TokenCurrentCompany $currentCompany,
        MoveCategoryAction $action,
        ApiResponse $response,
        string $category,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $category = $this->resolveCategory($company, $category);
        $this->authorizeCategoryMove($category);

        $validated = $request->validate([
            'parent_uuid' => ['nullable', 'uuid'],
        ]);

        $newParent = null;
        $parentUuid = $validated['parent_uuid'] ?? null;

        if (is_string($parentUuid) && $parentUuid !== '') {
            $newParent = $this->resolveCategory($company, $parentUuid);
        }

        $action->execute($this->actor($request), $company, $category, $newParent);
        $category->refresh()->load('parent');

        return $response->success(
            (new CategoryResource($category))->resolve($request),
        );
    }

    public function reorder(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ReorderSiblingCategoriesAction $action,
        ApiResponse $response,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $this->authorizeCategoryManage($company);

        $validated = $request->validate([
            'parent_uuid' => ['nullable', 'uuid'],
            'ordered_uuids' => ['required', 'array', 'min:1', 'max:500'],
            'ordered_uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $parent = null;
        $parentUuid = $validated['parent_uuid'] ?? null;

        if (is_string($parentUuid) && $parentUuid !== '') {
            $parent = $this->resolveCategory($company, $parentUuid);
        }

        /** @var list<string> $orderedUuids */
        $orderedUuids = array_values($validated['ordered_uuids']);
        $action->execute($this->actor($request), $company, $parent, $orderedUuids);

        return $response->success(['ordered_uuids' => $orderedUuids]);
    }

    public function archive(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ArchiveCategoryAction $action,
        ApiResponse $response,
        string $category,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $category = $this->resolveCategory($company, $category);
        $this->authorizeCategoryArchive($category);
        $action->execute($this->actor($request), $company, $category);
        $category->refresh()->load('parent');

        return $response->success(
            (new CategoryResource($category))->resolve($request),
        );
    }

    public function restore(
        Request $request,
        TokenCurrentCompany $currentCompany,
        RestoreCategoryAction $action,
        ApiResponse $response,
        string $category,
    ): JsonResponse {
        $company = $this->currentCompany($currentCompany);
        $category = $this->resolveCategory($company, $category);
        $this->authorizeCategoryRestore($category);
        $action->execute($this->actor($request), $company, $category);
        $category->refresh()->load('parent');

        return $response->success(
            (new CategoryResource($category))->resolve($request),
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
