<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Support\Catalog\AttributeValueFormatter;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class AttributeUsageController extends Controller
{
    public function show(CurrentCompany $currentCompany, string $attribute): View
    {
        $company = $currentCompany->require();
        $definition = AttributeDefinition::query()->forCompany($company)->where('uuid', $attribute)->firstOrFail();
        $this->authorize('view', $definition);

        $valueScope = fn (Relation $query): Relation => $query
            ->where('attribute_definition_id', $definition->getKey())
            ->with(['definition', 'selectedOption', 'selectedOptions']);

        $products = Product::query()->forCompany($company)
            ->whereHas('attributeValues', fn (Builder $query): Builder => $query
                ->where('attribute_definition_id', $definition->getKey()))
            ->with(['attributeValues' => $valueScope])
            ->orderBy('name')
            ->paginate(25, ['*'], 'products')->withQueryString();

        $variants = ProductVariant::query()->forCompany($company)
            ->whereHas('attributeValues', fn (Builder $query): Builder => $query
                ->where('attribute_definition_id', $definition->getKey()))
            ->with(['product:id,company_id,uuid,name,status', 'attributeValues' => $valueScope])
            ->orderBy('name')
            ->paginate(25, ['*'], 'variants')->withQueryString();

        return view('catalog.attributes.usage', [
            'company' => $company,
            'definition' => $definition,
            'products' => $products,
            'variants' => $variants,
            'formatter' => app(AttributeValueFormatter::class),
        ]);
    }
}
