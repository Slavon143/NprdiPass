<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Attributes\SyncVariantAttributeValuesAction;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Attributes\SyncVariantAttributesRequest;
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

class VariantAttributeController extends Controller
{
    public function edit(CurrentCompany $currentCompany, AttributeValueFormatter $formatter, string $product, string $variant): View
    {
        [$company, $product, $variant] = $this->context($currentCompany, $product, $variant);
        $this->authorize('manageAttributes', $variant);
        $definitions = AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Variant->value, AttributeScope::Both->value])
            ->with(['options' => fn ($query) => $query->ordered()])
            ->ordered()
            ->get();
        $variant->load(['attributeValues.definition', 'attributeValues.selectedOption', 'attributeValues.selectedOptions']);
        $product->load(['attributeValues.definition', 'attributeValues.selectedOption', 'attributeValues.selectedOptions']);

        return view('catalog.products.variants.attributes.edit', [
            'company' => $company,
            'product' => $product,
            'variant' => $variant,
            'definitions' => $definitions,
            'values' => $variant->attributeValues->keyBy('attribute_definition_id'),
            'productValues' => $product->attributeValues->keyBy('attribute_definition_id'),
            'archivedValues' => $variant->attributeValues->filter(fn (VariantAttributeValue $value): bool => $value->definition->status === AttributeDefinitionStatus::Archived),
            'formatter' => $formatter,
        ]);
    }

    public function update(SyncVariantAttributesRequest $request, CurrentCompany $currentCompany, SyncVariantAttributeValuesAction $action, string $product, string $variant): RedirectResponse
    {
        [$company, $product, $variant] = $this->context($currentCompany, $product, $variant);
        $attributes = $request->validated('attributes');
        $action->execute($this->actor($request), $company, $product, $variant, is_array($attributes) ? $attributes : []);

        return redirect()->route('catalog.products.variants.show', [$product->uuid, $variant->uuid])->with('success', 'Variant attributes updated.');
    }

    /** @return array{Company, Product, ProductVariant} */
    private function context(CurrentCompany $currentCompany, string $productUuid, string $variantUuid): array
    {
        $company = $currentCompany->require();
        $product = Product::query()->forCompany($company)->where('uuid', $productUuid)->firstOrFail();
        $variant = ProductVariant::query()->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('uuid', $variantUuid)
            ->firstOrFail();

        return [$company, $product, $variant];
    }

    private function actor(SyncVariantAttributesRequest $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
