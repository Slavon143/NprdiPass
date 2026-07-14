<?php

namespace App\Policies\Catalog;

use App\Enums\CompanyPermission;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\User;

class ProductPolicy extends CatalogPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $this->allowsCompany($user, $company, CompanyPermission::CatalogView);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->allowsModel($user, $product, CompanyPermission::CatalogView);
    }

    public function create(User $user, Company $company): bool
    {
        return $this->allowsCompany($user, $company, CompanyPermission::CatalogCreate);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->allowsModel($user, $product, CompanyPermission::CatalogUpdate);
    }

    public function archive(User $user, Product $product): bool
    {
        return $this->allowsModel($user, $product, CompanyPermission::CatalogArchive);
    }

    public function publish(User $user, Product $product): bool
    {
        return $this->allowsModel($user, $product, CompanyPermission::CatalogPublish);
    }

    public function manageMedia(User $user, Product $product): bool
    {
        return $this->allowsModel($user, $product, CompanyPermission::CatalogManageMedia);
    }
}
