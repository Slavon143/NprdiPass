<?php

namespace App\Authorization;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;

class CompanyPermissionMatrix
{
    public function allows(CompanyRole $role, CompanyPermission $permission): bool
    {
        return match ($role) {
            CompanyRole::Owner, CompanyRole::Admin => true,
            CompanyRole::Editor => in_array($permission, [
                CompanyPermission::CompanyView,
                CompanyPermission::MembersView,
                CompanyPermission::CatalogView,
                CompanyPermission::CatalogCreate,
                CompanyPermission::CatalogUpdate,
                CompanyPermission::CatalogManageMedia,
            ], true),
            CompanyRole::Viewer => in_array($permission, [
                CompanyPermission::CompanyView,
                CompanyPermission::CatalogView,
            ], true),
        };
    }
}
