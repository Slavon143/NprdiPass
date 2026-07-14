<?php

namespace App\Policies\Catalog;

use App\Enums\CompanyPermission;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;

class AttributeDefinitionPolicy extends CatalogPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $this->allowsCompany($user, $company, CompanyPermission::CatalogView);
    }

    public function view(User $user, AttributeDefinition $definition): bool
    {
        return $this->allowsModel($user, $definition, CompanyPermission::CatalogView);
    }

    public function create(User $user, Company $company): bool
    {
        return $this->allowsCompany($user, $company, CompanyPermission::CatalogManageAttributes);
    }

    public function update(User $user, AttributeDefinition $definition): bool
    {
        return $this->allowsModel($user, $definition, CompanyPermission::CatalogManageAttributes);
    }

    public function archive(User $user, AttributeDefinition $definition): bool
    {
        return $this->allowsModel($user, $definition, CompanyPermission::CatalogManageAttributes);
    }

    public function restore(User $user, AttributeDefinition $definition): bool
    {
        return $this->allowsModel($user, $definition, CompanyPermission::CatalogManageAttributes);
    }

    public function manageOptions(User $user, AttributeDefinition $definition): bool
    {
        return $this->allowsModel($user, $definition, CompanyPermission::CatalogManageAttributes);
    }
}
