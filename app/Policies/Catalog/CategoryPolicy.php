<?php

namespace App\Policies\Catalog;

use App\Enums\CompanyPermission;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;

class CategoryPolicy extends CatalogPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $this->allowsCompany($user, $company, CompanyPermission::CatalogView);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->allowsModel($user, $category, CompanyPermission::CatalogView);
    }

    public function create(User $user, Company $company): bool
    {
        return $this->allowsCompany($user, $company, CompanyPermission::CatalogManageCategories);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->allowsModel($user, $category, CompanyPermission::CatalogManageCategories);
    }

    public function move(User $user, Category $category): bool
    {
        return $this->allowsModel($user, $category, CompanyPermission::CatalogManageCategories);
    }

    public function reorder(User $user, Company $company): bool
    {
        return $this->allowsCompany($user, $company, CompanyPermission::CatalogManageCategories);
    }

    public function archive(User $user, Category $category): bool
    {
        return $this->allowsModel($user, $category, CompanyPermission::CatalogManageCategories);
    }

    public function restore(User $user, Category $category): bool
    {
        return $this->allowsModel($user, $category, CompanyPermission::CatalogManageCategories);
    }
}
