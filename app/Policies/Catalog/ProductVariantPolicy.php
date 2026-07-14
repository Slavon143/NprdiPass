<?php

namespace App\Policies\Catalog;

use App\Enums\CompanyPermission;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\User;

class ProductVariantPolicy extends CatalogPolicy
{
    public function view(User $user, ProductVariant $variant): bool
    {
        return $this->allowsModel($user, $variant, CompanyPermission::CatalogView);
    }

    public function create(User $user, Product $product): bool
    {
        return $this->allowsModel($user, $product, CompanyPermission::CatalogCreate);
    }

    public function update(User $user, ProductVariant $variant): bool
    {
        return $this->allowsModel($user, $variant, CompanyPermission::CatalogUpdate);
    }

    public function archive(User $user, ProductVariant $variant): bool
    {
        return $this->allowsModel($user, $variant, CompanyPermission::CatalogArchive);
    }

    public function setDefault(User $user, ProductVariant $variant): bool
    {
        $freshVariant = $this->freshModel($variant);

        if (! $freshVariant instanceof ProductVariant) {
            return false;
        }

        $product = Product::query()
            ->whereKey($freshVariant->getAttribute('product_id'))
            ->where('company_id', $freshVariant->getAttribute('company_id'))
            ->first();

        return $product !== null
            && $product->getAttribute('company_id') === $freshVariant->getAttribute('company_id')
            && $this->allowsModel($user, $freshVariant, CompanyPermission::CatalogUpdate);
    }

    public function manageMedia(User $user, ProductVariant $variant): bool
    {
        return $this->allowsModel($user, $variant, CompanyPermission::CatalogManageMedia);
    }
}
