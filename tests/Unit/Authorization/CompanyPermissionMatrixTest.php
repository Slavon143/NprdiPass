<?php

use App\Authorization\CompanyPermissionMatrix;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;

$matrixCases = [];

foreach (CompanyRole::cases() as $role) {
    foreach (CompanyPermission::cases() as $permission) {
        $expected = match ($role) {
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

        $matrixCases["{$role->value} / {$permission->value}"] = [
            $role,
            $permission,
            $expected,
        ];
    }
}

test('company permission matrix defines every role and permission combination', function (
    CompanyRole $role,
    CompanyPermission $permission,
    bool $expected,
) {
    expect((new CompanyPermissionMatrix)->allows($role, $permission))->toBe($expected);
})->with($matrixCases);
