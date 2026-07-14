<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\Attributes\SyncProductAttributeValuesAction;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\Attributes\SyncProductAttributesRequest;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Company;
use App\Models\User;
use App\Support\Catalog\AttributeValueFormatter;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ProductAttributeController extends Controller
{
    public function edit(CurrentCompany $currentCompany, AttributeValueFormatter $formatter, string $product): View
    {
        $company = $currentCompany->require();
        $product = $this->product($company, $product);
        $this->authorize('manageAttributes', $product);
        $definitions = AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->with(['options' => fn ($query) => $query->ordered()])
            ->ordered()
            ->get();
        $product->load(['attributeValues.definition', 'attributeValues.selectedOption', 'attributeValues.selectedOptions']);

        return view('catalog.products.attributes.edit', [
            'company' => $company,
            'product' => $product,
            'definitions' => $definitions,
            'values' => $product->attributeValues->keyBy('attribute_definition_id'),
            'archivedValues' => $product->attributeValues->filter(fn (ProductAttributeValue $value): bool => $value->definition->status === AttributeDefinitionStatus::Archived),
            'formatter' => $formatter,
        ]);
    }

    public function update(SyncProductAttributesRequest $request, CurrentCompany $currentCompany, SyncProductAttributeValuesAction $action, string $product): RedirectResponse
    {
        $company = $currentCompany->require();
        $product = $this->product($company, $product);
        $attributes = $request->validated('attributes');
        $action->execute($this->actor($request), $company, $product, is_array($attributes) ? $attributes : []);

        return redirect()->route('catalog.products.show', $product->uuid)->with('success', 'Product attributes updated.');
    }

    private function product(Company $company, string $uuid): Product
    {
        return Product::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function actor(SyncProductAttributesRequest $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
