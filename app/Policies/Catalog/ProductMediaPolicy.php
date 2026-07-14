<?php

namespace App\Policies\Catalog;

use App\Enums\CompanyPermission;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\User;

class ProductMediaPolicy extends CatalogPolicy
{
    public function view(User $user, ProductMedia $media): bool
    {
        return $this->allowsModel($user, $media, CompanyPermission::CatalogView);
    }

    public function create(User $user, Product $product): bool
    {
        return $this->allowsModel($user, $product, CompanyPermission::CatalogManageMedia);
    }

    public function createForProduct(User $user, Product $product): bool
    {
        return $this->create($user, $product);
    }

    public function createForVariant(User $user, ProductVariant $variant): bool
    {
        return $this->allowsModel($user, $variant, CompanyPermission::CatalogManageMedia);
    }

    public function update(User $user, ProductMedia $media): bool
    {
        return $this->allowsModel($user, $media, CompanyPermission::CatalogManageMedia);
    }

    public function delete(User $user, ProductMedia $media): bool
    {
        return $this->allowsModel($user, $media, CompanyPermission::CatalogManageMedia);
    }

    public function setPrimary(User $user, ProductMedia $media): bool
    {
        return $this->update($user, $media);
    }

    public function reorder(User $user, ProductMedia $media): bool
    {
        return $this->update($user, $media);
    }
}
