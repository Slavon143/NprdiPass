<?php

namespace App\Policies\Catalog;

use App\Enums\CompanyPermission;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
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

    public function update(User $user, ProductMedia $media): bool
    {
        return $this->allowsModel($user, $media, CompanyPermission::CatalogManageMedia);
    }

    public function delete(User $user, ProductMedia $media): bool
    {
        return $this->allowsModel($user, $media, CompanyPermission::CatalogManageMedia);
    }
}
