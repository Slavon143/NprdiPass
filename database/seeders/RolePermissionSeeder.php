<?php

namespace Database\Seeders;

use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $permissions = collect(PlatformPermission::cases())
            ->map(fn (PlatformPermission $permission): Permission => PermissionModel::findOrCreate(
                $permission->value,
                'web',
            ));

        $role = Role::findOrCreate(PlatformRole::SuperAdmin->value, 'web');
        $role->syncPermissions($permissions);

        $registrar->forgetCachedPermissions();
    }
}
