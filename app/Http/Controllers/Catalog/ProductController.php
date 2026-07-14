<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\CompanyPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Products\StoreProductRequest;
use App\Http\Requests\Catalog\Products\UpdateProductRequest;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use App\Services\Catalog\CategoryHierarchyService;
use App\Support\Catalog\AttributeValueFormatter;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize('viewAny', [Product::class, $company]);
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,active,archived,all'],
            'primary_category' => ['nullable', 'uuid'],
        ]);
        $query = Product::query()
            ->forCompany($company)
            ->with(['primaryCategory:id,uuid,name', 'defaultVariant:id,uuid,product_id,name,sku,status', 'primaryMedia:id,uuid,product_id,alt_text,mime_type'])
            ->withCount(['categories', 'variants', 'productMedia']);
        $status = $filters['status'] ?? 'all';

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $primaryCategoryUuid = $filters['primary_category'] ?? null;

        if (is_string($primaryCategoryUuid) && $primaryCategoryUuid !== '') {
            $primaryCategory = $this->resolveCategory($company, $primaryCategoryUuid);
            $query->where('primary_category_id', $primaryCategory->getKey());
        }

        return view()->make('catalog.products.index', [
            'company' => $company,
            'products' => $query->latest('updated_at')->latest('id')->paginate(25)->withQueryString(),
            'categoryOptions' => $this->activeCategories($company),
            'canCreate' => $request->user()?->can('create', [Product::class, $company]) === true,
            'canUpdate' => $request->user()?->can(CompanyPermission::CatalogUpdate->value, $company) === true,
            'filters' => ['status' => $status, 'primary_category' => $primaryCategoryUuid],
        ]);
    }

    public function create(Request $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize('create', [Product::class, $company]);

        return view()->make('catalog.products.create', [
            'company' => $company,
            'categoryOptions' => $this->activeCategories($company),
        ]);
    }

    public function store(
        StoreProductRequest $request,
        CurrentCompany $currentCompany,
        CreateProductAction $action,
    ): RedirectResponse {
        $validated = $request->validated();
        $product = $action->execute(
            $this->actor($request),
            $currentCompany->require(),
            $validated,
            $this->primaryCategoryUuid($validated),
            $this->categoryUuids($validated),
        );

        return redirect()->route('catalog.products.show', $product->uuid)
            ->with('success', 'Product created.');
    }

    public function show(Request $request, CurrentCompany $currentCompany, AttributeValueFormatter $attributeFormatter, string $product): View
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $this->authorize('view', $product);
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

        $attributeDefinitions = AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->ordered()
            ->get();

        return view()->make('catalog.products.show', [
            'company' => $company,
            'product' => $product,
            'canUpdate' => $request->user()?->can('update', $product) === true,
            'canCreateVariant' => $request->user()?->can('create', [ProductVariant::class, $product]) === true,
            'canManageAttributes' => $request->user()?->can('manageAttributes', $product) === true,
            'canManageMedia' => $request->user()?->can('manageMedia', $product) === true,
            'attributeDefinitions' => $attributeDefinitions,
            'attributeValues' => $product->attributeValues->keyBy('attribute_definition_id'),
            'archivedAttributeValues' => $product->attributeValues->filter(fn (ProductAttributeValue $value): bool => $value->definition->status === AttributeDefinitionStatus::Archived),
            'attributeFormatter' => $attributeFormatter,
        ]);
    }

    public function edit(Request $request, CurrentCompany $currentCompany, string $product): View
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $this->authorize('update', $product);
        $product->load(['categories', 'primaryCategory', 'defaultVariant']);

        return view()->make('catalog.products.edit', [
            'company' => $company,
            'product' => $product,
            'categoryOptions' => $this->activeCategories($company),
            'selectedCategoryUuids' => $product->categories
                ->reject(fn (Category $category): bool => $category->is($product->primaryCategory))
                ->pluck('uuid')
                ->all(),
        ]);
    }

    public function update(
        UpdateProductRequest $request,
        CurrentCompany $currentCompany,
        UpdateProductAction $action,
        string $product,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $validated = $request->validated();
        $updated = $action->execute(
            $this->actor($request),
            $company,
            $product,
            $validated,
            $this->primaryCategoryUuid($validated),
            $this->categoryUuids($validated),
        );

        return redirect()->route('catalog.products.show', $updated->uuid)
            ->with('success', 'Product updated.');
    }

    private function resolveProduct(Company $company, string $uuid): Product
    {
        return Product::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function resolveCategory(Company $company, string $uuid): Category
    {
        return Category::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function activeCategories(Company $company)
    {
        return Category::query()->forCompany($company)
            ->where('status', CategoryStatus::Active->value)
            ->ordered()
            ->limit(CategoryHierarchyService::MAX_CATEGORIES_PER_COMPANY + 1)
            ->get();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @param array<string, mixed> $validated */
    private function primaryCategoryUuid(array $validated): ?string
    {
        $uuid = $validated['primary_category_uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private function categoryUuids(array $validated): array
    {
        $uuids = $validated['category_uuids'] ?? [];

        return is_array($uuids) ? array_values(array_filter($uuids, 'is_string')) : [];
    }
}
