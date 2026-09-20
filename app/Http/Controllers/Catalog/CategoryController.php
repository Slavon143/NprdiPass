<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Categories\BulkDeleteCategoriesAction;
use App\Actions\Catalog\Categories\CreateCategoryAction;
use App\Actions\Catalog\Categories\DeleteCategoryAction;
use App\Actions\Catalog\Categories\UpdateCategoryAction;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Exceptions\Catalog\CategoryOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Categories\BulkDeleteCategoriesRequest;
use App\Http\Requests\Catalog\Categories\StoreCategoryRequest;
use App\Http\Requests\Catalog\Categories\UpdateCategoryRequest;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CategoryHierarchyService;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CategoryController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize('viewAny', [Category::class, $company]);
        $filters = $request->validate([
            'status' => ['nullable', 'in:active,archived,all'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'all';
        $search = trim((string) ($filters['search'] ?? ''));
        $hasActiveFilters = $status !== 'all' || $search !== '';

        $allCategories = Category::query()
            ->forCompany($company)
            ->withCount([
                'children as children_count',
                'primaryProducts as primary_products_count' => fn (Builder $query): Builder => $query
                    ->where('status', '!=', ProductStatus::Archived->value),
                'products as assigned_products_count' => fn (Builder $query): Builder => $query
                    ->where('products.status', '!=', ProductStatus::Archived->value),
            ])
            ->limit(CategoryHierarchyService::MAX_CATEGORIES_PER_COMPANY + 1)
            ->ordered()
            ->get();

        $byId = $allCategories->keyBy('id');
        $matchedIds = $allCategories->filter(function (Category $category) use ($status, $search): bool {
            if ($status === CategoryStatus::Active->value && $category->status->value !== CategoryStatus::Active->value) {
                return false;
            }

            if ($status === CategoryStatus::Archived->value && $category->status->value !== CategoryStatus::Archived->value) {
                return false;
            }

            if ($search !== '') {
                $needle = mb_strtolower($search);

                if (! str_contains(mb_strtolower($category->name), $needle)
                    && ! str_contains(mb_strtolower($category->slug), $needle)) {
                    return false;
                }
            }

            return true;
        })->pluck('id');

        $visibleIds = $hasActiveFilters ? $matchedIds : $allCategories->pluck('id');

        if ($hasActiveFilters) {
            foreach ($matchedIds as $matchedId) {
                $parentId = $byId->get($matchedId)?->parent_id;

                while ($parentId !== null) {
                    if (! $visibleIds->contains($parentId)) {
                        $visibleIds->push($parentId);
                    }

                    $parentId = $byId->get($parentId)?->parent_id;
                }
            }
        }

        $childrenMap = [];

        foreach ($allCategories as $category) {
            $childrenMap[(int) ($category->parent_id ?? 0)][] = $category;
        }

        $tree = $this->buildCategoryTree(0, $childrenMap, $visibleIds);

        $initialExpanded = $hasActiveFilters
            ? $visibleIds->unique()->mapWithKeys(fn (int $id): array => [$id => true])->all()
            : collect($tree)->mapWithKeys(fn (array $node): array => [$node['category']->id => true])->all();

        return view()->make('catalog.categories.index', [
            'company' => $company,
            'tree' => $tree,
            'totalCount' => $allCategories->count(),
            'visibleCount' => $visibleIds->unique()->count(),
            'hasActiveFilters' => $hasActiveFilters,
            'initialExpanded' => $initialExpanded,
            'canManage' => $request->user()?->can('create', [Category::class, $company]) === true,
            'reorderPayloads' => $this->reorderPayloads($allCategories),
            'filters' => ['status' => $status, 'search' => $search],
        ]);
    }

    public function create(Request $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize('create', [Category::class, $company]);

        return view()->make('catalog.categories.create', [
            'company' => $company,
            'parentOptions' => $this->activeParents($company),
            'selectedParent' => $request->string('parent')->toString(),
        ]);
    }

    public function store(
        StoreCategoryRequest $request,
        CurrentCompany $currentCompany,
        CreateCategoryAction $action,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $actor = $this->actor($request);
        $data = $request->validated();
        $parent = $this->optionalParent($company, $data['parent_uuid'] ?? null);
        $category = $action->execute($actor, $company, $data, $parent);

        return redirect()->route('catalog.categories.edit', $category->uuid)
            ->with('success', 'Category created.');
    }

    public function edit(
        Request $request,
        CurrentCompany $currentCompany,
        CategoryHierarchyService $hierarchy,
        string $category,
    ): View {
        $company = $currentCompany->require();
        $category = $this->resolveCategory($company, $category);
        $this->authorize('update', $category);
        $allCategories = Category::query()
            ->forCompany($company)
            ->limit(CategoryHierarchyService::MAX_CATEGORIES_PER_COMPANY + 1)
            ->ordered()
            ->get();
        $excludedIds = [$category->getKey(), ...$hierarchy->descendantIds($company, $category, $allCategories)];

        return view()->make('catalog.categories.edit', [
            'company' => $company,
            'category' => $category,
            'parentOptions' => $allCategories
                ->where('status', CategoryStatus::Active)
                ->whereNotIn('id', $excludedIds),
            'childrenCount' => $category->children()->count(),
            'activeChildrenCount' => $category->children()
                ->where('status', CategoryStatus::Active->value)->count(),
            'primaryProductsCount' => $category->primaryProducts()
                ->where('status', '!=', ProductStatus::Archived->value)->count(),
            'activePrimaryProductsCount' => $category->primaryProducts()
                ->where('status', ProductStatus::Active->value)->count(),
            'assignedProductsCount' => $category->products()
                ->where('products.status', '!=', ProductStatus::Archived->value)->count(),
            'activeAssignedProductsCount' => $category->products()
                ->where('products.status', ProductStatus::Active->value)->count(),
        ]);
    }

    public function update(
        UpdateCategoryRequest $request,
        CurrentCompany $currentCompany,
        UpdateCategoryAction $action,
        string $category,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $category = $this->resolveCategory($company, $category);
        $action->execute($this->actor($request), $company, $category, $request->validated());

        return redirect()->route('catalog.categories.edit', $category->uuid)
            ->with('success', 'Category updated.');
    }

    public function destroy(
        Request $request,
        CurrentCompany $currentCompany,
        DeleteCategoryAction $action,
        string $category,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $category = $this->resolveCategory($company, $category);

        try {
            $action->execute($this->actor($request), $company, $category);
        } catch (CategoryOperationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('catalog.categories.index')
            ->with('success', 'Category deleted.');
    }

    public function bulkDestroy(
        BulkDeleteCategoriesRequest $request,
        CurrentCompany $currentCompany,
        BulkDeleteCategoriesAction $action,
    ): RedirectResponse {
        $company = $currentCompany->require();

        $result = $action->execute(
            $this->actor($request),
            $company,
            $request->validated('categories'),
        );

        if ($result['blocked'] !== []) {
            return back()->with('error', $this->blockedMessage('Deletion blocked.', $result['blocked']));
        }

        return redirect()->route('catalog.categories.index')
            ->with('success', trans_choice(':count category deleted.|:count categories deleted.', count($result['deleted']), [
                'count' => count($result['deleted']),
            ]));
    }

    private function resolveCategory(Company $company, string $uuid): Category
    {
        return Category::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function optionalParent(Company $company, mixed $uuid): ?Category
    {
        return is_string($uuid) && $uuid !== '' ? $this->resolveCategory($company, $uuid) : null;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /**
     * @param  list<array{uuid: string, name: string, reason: string}>  $blocked
     */
    private function blockedMessage(string $heading, array $blocked): string
    {
        $lines = [$heading];

        foreach ($blocked as $item) {
            $lines[] = "{$item['name']}: {$item['reason']}";
        }

        return implode("\n", $lines);
    }

    private function activeParents(Company $company)
    {
        return Category::query()->forCompany($company)->active()->ordered()
            ->limit(CategoryHierarchyService::MAX_CATEGORIES_PER_COMPANY + 1)->get();
    }

    /**
     * @param  array<int, list<Category>>  $childrenMap
     * @param  Collection<int, int>  $visibleIds
     * @return list<array{category: Category, children: list<array{category: Category, children: mixed}>}>
     */
    private function buildCategoryTree(int $parentId, array $childrenMap, Collection $visibleIds): array
    {
        $nodes = [];

        foreach ($childrenMap[$parentId] ?? [] as $category) {
            if (! $visibleIds->contains($category->getKey())) {
                continue;
            }

            $nodes[] = [
                'category' => $category,
                'children' => $this->buildCategoryTree($category->getKey(), $childrenMap, $visibleIds),
            ];
        }

        return $nodes;
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<string, array{up: list<string>|null, down: list<string>|null, parent_uuid: string|null}>
     */
    private function reorderPayloads($categories): array
    {
        $payloads = [];
        $byId = $categories->keyBy('id');

        foreach ($categories->groupBy(fn (Category $category): string => (string) ($category->parent_id ?? 'root')) as $siblings) {
            $uuids = $siblings->pluck('uuid')->map('strval')->values()->all();

            foreach ($siblings->values() as $index => $category) {
                $up = null;
                $down = null;

                if ($index > 0) {
                    $up = $uuids;
                    [$up[$index - 1], $up[$index]] = [$up[$index], $up[$index - 1]];
                }

                if ($index < count($uuids) - 1) {
                    $down = $uuids;
                    [$down[$index + 1], $down[$index]] = [$down[$index], $down[$index + 1]];
                }

                $parent = $category->parent_id === null ? null : $byId->get($category->parent_id);
                $payloads[$category->uuid] = [
                    'up' => $up,
                    'down' => $down,
                    'parent_uuid' => $parent instanceof Category ? $parent->uuid : null,
                ];
            }
        }

        return $payloads;
    }
}
