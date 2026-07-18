<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Lifecycle\ArchiveProductAction;
use App\Actions\Catalog\Lifecycle\BulkArchiveProductsAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Data\Catalog\Search\CatalogProductSearchCriteria;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Products\BulkArchiveProductsRequest;
use App\Http\Requests\Catalog\Products\StoreProductRequest;
use App\Http\Requests\Catalog\Products\UpdateProductRequest;
use App\Http\Requests\Catalog\Search\SearchProductsRequest;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Queries\Catalog\ProductCatalogQuery;
use App\Services\Catalog\CategoryHierarchyService;
use App\Services\Catalog\ProductActivationReadinessService;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ProductIndexReadinessProvider;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Support\Catalog\AttributeValueFormatter;
use App\Support\Catalog\Search\CatalogSearchStringNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(
        SearchProductsRequest $request,
        CurrentCompany $currentCompany,
        ProductCatalogQuery $catalogQuery,
        CategoryHierarchyService $hierarchy,
        CatalogSearchStringNormalizer $searchNormalizer,
    ): View {
        $company = $currentCompany->require();
        $criteria = $request->toCriteria($company, $hierarchy, $searchNormalizer);
        $products = $catalogQuery->build($company, $criteria)
            ->paginate($criteria->perPage)
            ->withQueryString();

        $readinessProvider = app(ProductIndexReadinessProvider::class);
        $passportSummaries = $readinessProvider->loadSummaries($company, $products);

        return view()->make('catalog.products.index', [
            'company' => $company,
            'products' => $products,
            'criteria' => $criteria,
            'hasProducts' => Product::query()->forCompany($company)->exists(),
            'categoryOptions' => $this->activeCategories($company),
            'brandOptions' => $this->distinctProductValues($company, 'brand'),
            'manufacturerOptions' => $this->distinctProductValues($company, 'manufacturer'),
            'attributeFilterDefinitions' => $this->filterableAttributeDefinitions($company),
            'activeFilterChips' => $this->activeFilterChips($request, $criteria),
            'passportSummaries' => $passportSummaries,
            'canCreate' => $request->user()?->can('create', [Product::class, $company]) === true,
            'canUpdate' => $request->user()?->can(CompanyPermission::CatalogUpdate->value, $company) === true,
            'canArchive' => $request->user()?->can(CompanyPermission::CatalogArchive->value, $company) === true,
            'canManagePassports' => $request->user()?->can(CompanyPermission::PassportsManage->value, $company) === true,
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

    public function show(
        Request $request,
        CurrentCompany $currentCompany,
        AttributeValueFormatter $attributeFormatter,
        ProductActivationReadinessService $readinessService,
        ReadinessContextBuilder $passportContextBuilder,
        PassportReadinessEvaluator $passportEvaluator,
        string $product,
    ): View {
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
            'passport.currentDraftVersion',
        ])->loadCount(['variants', 'productMedia']);

        $attributeDefinitions = AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->ordered()
            ->get();
        $readiness = $readinessService->evaluate($company, $product);
        $isArchived = $product->status === ProductStatus::Archived;

        $passport = $product->passport;
        $passportReadiness = null;
        $canManagePassports = $request->user()?->can(CompanyPermission::PassportsManage->value, $company) === true;
        $canViewPassports = $canManagePassports || $request->user()?->can(CompanyPermission::PassportsView->value, $company) === true;

        if ($passport instanceof ProductPassport) {
            $passportContext = $passportContextBuilder->build($company, $product);
            $passportReadiness = $passportEvaluator->evaluate($passportContext);
        }

        return view()->make('catalog.products.show', [
            'company' => $company,
            'product' => $product,
            'canUpdate' => ! $isArchived && $request->user()?->can('update', $product) === true,
            'canCreateVariant' => ! $isArchived && $request->user()?->can('create', [ProductVariant::class, $product]) === true,
            'canManageAttributes' => ! $isArchived && $request->user()?->can('manageAttributes', $product) === true,
            'canManageMedia' => ! $isArchived && $request->user()?->can('manageMedia', $product) === true,
            'canManageDocuments' => $request->user()?->can('viewAny', [ProductDocument::class, $company]) === true,
            'documentCount' => ProductDocument::query()->forCompany($company)->where('product_id', $product->getKey())->active()->count(),
            'canActivate' => $request->user()?->can('activate', $product) === true,
            'canReturnToDraft' => $request->user()?->can('returnToDraft', $product) === true,
            'canArchive' => $request->user()?->can('archive', $product) === true,
            'canRestore' => $request->user()?->can('restore', $product) === true,
            'readiness' => $readiness,
            'passport' => $passport,
            'passportReadiness' => $passportReadiness,
            'canManagePassports' => $canManagePassports,
            'canViewPassports' => $canViewPassports,
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

    public function bulkDestroy(
        BulkArchiveProductsRequest $request,
        CurrentCompany $currentCompany,
        BulkArchiveProductsAction $action,
    ): RedirectResponse {
        $company = $currentCompany->require();

        $archived = $action->execute(
            $this->actor($request),
            $company,
            $request->validated('products'),
        );

        $query = $request->query();

        return redirect()
            ->route('catalog.products.index', $query)
            ->with('success', trans_choice(':count product archived.|:count products archived.', count($archived), [
                'count' => count($archived),
            ]));
    }

    public function destroy(
        Request $request,
        CurrentCompany $currentCompany,
        ArchiveProductAction $action,
        string $product,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);

        $action->execute($this->actor($request), $company, $product);

        return redirect()
            ->route('catalog.products.index')
            ->with('success', __('Product deleted.'));
    }

    private function resolveProduct(Company $company, string $uuid): Product
    {
        return Product::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function activeCategories(Company $company)
    {
        return Category::query()->forCompany($company)
            ->where('status', CategoryStatus::Active->value)
            ->ordered()
            ->limit(CategoryHierarchyService::MAX_CATEGORIES_PER_COMPANY + 1)
            ->get();
    }

    private function filterableAttributeDefinitions(Company $company)
    {
        return AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->where('filterable', true)
            ->where('type', '!=', AttributeDataType::Text->value)
            ->with(['options' => fn ($query) => $query
                ->where('status', 'active')
                ->ordered()])
            ->ordered()
            ->limit(50)
            ->get();
    }

    /** @return list<string> */
    private function distinctProductValues(Company $company, string $column): array
    {
        return Product::query()
            ->forCompany($company)
            ->whereNotNull($column)
            ->whereRaw("TRIM({$column}) <> ''")
            ->distinct()
            ->orderBy($column)
            ->limit(100)
            ->pluck($column)
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function activeFilterChips(SearchProductsRequest $request, CatalogProductSearchCriteria $criteria): array
    {
        $chips = [];
        $base = $request->query();
        $route = fn (array $query): string => route('catalog.products.index', array_filter($query, fn (mixed $value): bool => $value !== null && $value !== []));

        if ($criteria->query !== '') {
            $query = $base;
            unset($query['q']);
            $chips[] = ['label' => 'Search: '.$criteria->query, 'url' => $route($query)];
        }

        foreach (['brand' => 'Brand', 'manufacturer' => 'Manufacturer', 'readiness' => 'Readiness'] as $key => $label) {
            $value = $base[$key] ?? null;
            if (is_string($value) && $value !== '' && ! ($key === 'readiness' && $value === 'any')) {
                $query = $base;
                unset($query[$key]);
                $chips[] = ['label' => "{$label}: {$value}", 'url' => $route($query)];
            }
        }

        foreach (['product_statuses' => 'Product status', 'variant_statuses' => 'Variant status', 'category_uuids' => 'Category', 'missing_data' => 'Missing', 'passport_statuses' => 'Passport'] as $key => $label) {
            $values = $base[$key] ?? [];
            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $index => $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }

                $query = $base;
                unset($query[$key][$index]);
                $query[$key] = array_values($query[$key] ?? []);
                $chips[] = ['label' => "{$label}: {$value}", 'url' => $route($query)];
            }
        }

        if ($criteria->needsAttention) {
            $query = $base;
            unset($query['needs_attention']);
            $chips[] = ['label' => 'Needs attention', 'url' => $route($query)];
        }

        if (($base['attributes'] ?? []) !== [] && is_array($base['attributes'])) {
            $query = $base;
            unset($query['attributes']);
            $chips[] = ['label' => 'Attribute filters', 'url' => $route($query)];
        }

        return $chips;
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
