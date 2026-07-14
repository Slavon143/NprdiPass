<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Categories\CreateCategoryAction;
use App\Actions\Catalog\Categories\UpdateCategoryAction;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Http\Controllers\Controller;
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
            'parent' => ['nullable', 'string', 'max:36'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $query = Category::query()
            ->forCompany($company)
            ->with('parent:id,uuid,name')
            ->withCount([
                'children as active_children_count' => fn (Builder $query): Builder => $query
                    ->where('status', CategoryStatus::Active->value),
                'primaryProducts as active_primary_products_count' => fn (Builder $query): Builder => $query->where('status', ProductStatus::Active->value),
            ]);
        $status = $filters['status'] ?? 'all';

        if ($status === CategoryStatus::Active->value) {
            $query->active();
        } elseif ($status === CategoryStatus::Archived->value) {
            $query->archived();
        }

        $parentFilter = $filters['parent'] ?? null;

        if ($parentFilter === 'root') {
            $query->roots();
        } elseif (is_string($parentFilter) && $parentFilter !== '') {
            $parent = $this->resolveCategory($company, $parentFilter);
            $query->where('parent_id', $parent->getKey());
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $allCategories = Category::query()
            ->forCompany($company)
            ->limit(CategoryHierarchyService::MAX_CATEGORIES_PER_COMPANY + 1)
            ->ordered()
            ->get();

        return view()->make('catalog.categories.index', [
            'company' => $company,
            'categories' => $query->ordered()->paginate(50)->withQueryString(),
            'parentOptions' => $allCategories->where('status', CategoryStatus::Active),
            'canManage' => $request->user()?->can('create', [Category::class, $company]) === true,
            'reorderPayloads' => $this->reorderPayloads($allCategories),
            'filters' => ['status' => $status, 'parent' => $parentFilter, 'search' => $search],
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
            'activeChildrenCount' => $category->children()
                ->where('status', CategoryStatus::Active->value)->count(),
            'activePrimaryProductsCount' => $category->primaryProducts()
                ->where('status', ProductStatus::Active->value)->count(),
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

    private function activeParents(Company $company)
    {
        return Category::query()->forCompany($company)->active()->ordered()
            ->limit(CategoryHierarchyService::MAX_CATEGORIES_PER_COMPANY + 1)->get();
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
