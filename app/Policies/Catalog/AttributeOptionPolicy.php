<?php

namespace App\Policies\Catalog;

use App\Enums\CompanyPermission;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\User;

class AttributeOptionPolicy extends CatalogPolicy
{
    public function view(User $user, AttributeOption $option): bool
    {
        return $this->allowsModel($user, $option, CompanyPermission::CatalogView);
    }

    public function create(User $user, AttributeDefinition $definition): bool
    {
        return $this->allowsModel($user, $definition, CompanyPermission::CatalogManageAttributes);
    }

    public function update(User $user, AttributeOption $option): bool
    {
        return $this->allowsModel($user, $option, CompanyPermission::CatalogManageAttributes);
    }

    public function archive(User $user, AttributeOption $option): bool
    {
        return $this->allowsModel($user, $option, CompanyPermission::CatalogManageAttributes);
    }
}
