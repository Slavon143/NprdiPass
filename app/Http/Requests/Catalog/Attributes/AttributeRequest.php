<?php

namespace App\Http\Requests\Catalog\Attributes;

use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class AttributeRequest extends FormRequest
{
    protected function currentCompany(): ?Company
    {
        $company = $this->attributes->get('currentCompany');

        return $company instanceof Company ? $company : null;
    }

    protected function actor(): ?User
    {
        $actor = $this->user();

        return $actor instanceof User ? $actor : null;
    }

    protected function definition(): ?AttributeDefinition
    {
        $company = $this->currentCompany();
        $uuid = $this->route('attribute');

        if ($company === null || ! is_string($uuid)) {
            return null;
        }

        return AttributeDefinition::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    protected function option(AttributeDefinition $definition): ?AttributeOption
    {
        $company = $this->currentCompany();
        $id = $this->route('option');

        if ($company === null || filter_var($id, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return AttributeOption::query()->forCompany($company)
            ->where('attribute_definition_id', $definition->getKey())
            ->whereKey((int) $id)
            ->firstOrFail();
    }

    protected function product(): ?Product
    {
        $company = $this->currentCompany();
        $uuid = $this->route('product');

        if ($company === null || ! is_string($uuid)) {
            return null;
        }

        return Product::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    protected function variant(Product $product): ?ProductVariant
    {
        $company = $this->currentCompany();
        $uuid = $this->route('variant');

        if ($company === null || ! is_string($uuid)) {
            return null;
        }

        return ProductVariant::query()->forCompany($company)
            ->where('product_id', $product->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
