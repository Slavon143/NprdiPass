<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Variants\CreateProductVariantAction;
use App\Actions\Catalog\Variants\UpdateProductVariantAction;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Variants\StoreProductVariantRequest;
use App\Http\Requests\Catalog\Variants\UpdateProductVariantRequest;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use App\Models\User;
use App\Support\Catalog\AttributeValueFormatter;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductVariantController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, string $product): View
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $this->authorize('view', $product);
        $product->load('defaultVariant');

        return view()->make('catalog.products.variants.index', [
            'company' => $company,
            'product' => $product,
            'variants' => ProductVariant::query()
                ->forCompany($company)
                ->where('product_id', $product->getKey())
                ->ordered()
                ->paginate(25),
            'canCreate' => $request->user()?->can('create', [ProductVariant::class, $product]) === true,
            'canUpdate' => $request->user()?->can(CompanyPermission::CatalogUpdate->value, $company) === true,
            'canArchive' => $request->user()?->can(CompanyPermission::CatalogArchive->value, $company) === true,
            'availableVariantCount' => ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->available()->count(),
        ]);
    }

    public function create(Request $request, CurrentCompany $currentCompany, string $product): View
    {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $this->authorize('create', [ProductVariant::class, $product]);
        $product->load('defaultVariant');

        return view()->make('catalog.products.variants.create', compact('company', 'product'));
    }

    public function store(
        StoreProductVariantRequest $request,
        CurrentCompany $currentCompany,
        CreateProductVariantAction $action,
        string $product,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $variant = $action->execute($this->actor($request), $company, $product, $request->validated());

        return redirect()->route('catalog.products.variants.show', [$product->uuid, $variant->uuid])
            ->with('success', 'Variant created.');
    }

    public function show(
        Request $request,
        CurrentCompany $currentCompany,
        AttributeValueFormatter $attributeFormatter,
        string $product,
        string $variant,
    ): View {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $this->authorize('view', $variant);
        $product->load(['defaultVariant', 'primaryMedia']);
        $variant->load(['primaryMedia', 'createdBy', 'updatedBy', 'attributeValues.definition', 'attributeValues.selectedOption', 'attributeValues.selectedOptions'])->loadCount('media');
        $attributeDefinitions = AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Variant->value, AttributeScope::Both->value])
            ->ordered()
            ->get();
        $isArchived = $product->status === ProductStatus::Archived || $variant->status === ProductVariantStatus::Archived;
        $availableVariantCount = ProductVariant::query()->forCompany($company)->where('product_id', $product->getKey())->available()->count();

        return view()->make('catalog.products.variants.show', [
            'company' => $company,
            'product' => $product,
            'variant' => $variant,
            'canUpdate' => ! $isArchived && $request->user()?->can('update', $variant) === true,
            'canSetDefault' => ! $isArchived && $request->user()?->can('setDefault', $variant) === true,
            'canManageAttributes' => ! $isArchived && $request->user()?->can('manageAttributes', $variant) === true,
            'canManageMedia' => ! $isArchived && $request->user()?->can('manageMedia', $variant) === true,
            'canArchive' => $request->user()?->can('archive', $variant) === true,
            'canRestore' => $request->user()?->can('restore', $variant) === true,
            'availableVariantCount' => $availableVariantCount,
            'attributeDefinitions' => $attributeDefinitions,
            'attributeValues' => $variant->attributeValues->keyBy('attribute_definition_id'),
            'archivedAttributeValues' => $variant->attributeValues->filter(fn (VariantAttributeValue $value): bool => $value->definition->status === AttributeDefinitionStatus::Archived),
            'attributeFormatter' => $attributeFormatter,
        ]);
    }

    public function edit(
        Request $request,
        CurrentCompany $currentCompany,
        string $product,
        string $variant,
    ): View {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $this->authorize('update', $variant);
        $product->load('defaultVariant');

        return view()->make('catalog.products.variants.edit', compact('company', 'product', 'variant'));
    }

    public function update(
        UpdateProductVariantRequest $request,
        CurrentCompany $currentCompany,
        UpdateProductVariantAction $action,
        string $product,
        string $variant,
    ): RedirectResponse {
        $company = $currentCompany->require();
        $product = $this->resolveProduct($company, $product);
        $variant = $this->resolveVariant($company, $product, $variant);
        $variant = $action->execute(
            $this->actor($request),
            $company,
            $product,
            $variant,
            $request->validated(),
        );

        return redirect()->route('catalog.products.variants.show', [$product->uuid, $variant->uuid])
            ->with('success', 'Variant updated.');
    }

    private function resolveProduct(Company $company, string $uuid): Product
    {
        return Product::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function resolveVariant(Company $company, Product $product, string $uuid): ProductVariant
    {
        return ProductVariant::query()
            ->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
