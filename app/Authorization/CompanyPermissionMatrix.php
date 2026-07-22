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
                CompanyPermission::CatalogViewDocuments,
                CompanyPermission::CatalogManageDocuments,
                CompanyPermission::CatalogSubmitDocumentReview,
                CompanyPermission::PassportsView,
                CompanyPermission::PassportsManage,
            ], true),
            CompanyRole::Viewer => in_array($permission, [
                CompanyPermission::CompanyView,
                CompanyPermission::CatalogView,
                CompanyPermission::CatalogViewDocuments,
                CompanyPermission::PassportsView,
            ], true),
        };
    }
}
