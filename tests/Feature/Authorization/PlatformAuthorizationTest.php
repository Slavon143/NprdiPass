<?php

use App\Enums\CompanyPermission;
use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Database\Seeders\LocalDevelopmentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('platform role seeder is idempotent and grants every platform permission', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);

    $role = Role::findByName(PlatformRole::SuperAdmin->value, 'web');

    expect(Role::query()->where('name', PlatformRole::SuperAdmin->value)->count())->toBe(1)
        ->and(Permission::query()->whereIn(
            'name',
            array_column(PlatformPermission::cases(), 'value'),
        )->count())->toBe(count(PlatformPermission::cases()))
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(PlatformPermission::cases())->pluck('value')->sort()->values()->all());
});

test('local super admin receives the platform role but no company membership', function () {
    $this->seed(LocalDevelopmentSeeder::class);

    $user = User::query()->where('email', 'superadmin@nordipass.local')->firstOrFail();

    expect($user->hasRole(PlatformRole::SuperAdmin->value))->toBeTrue()
        ->and($user->memberships()->count())->toBe(0);

    foreach (PlatformPermission::cases() as $permission) {
        expect($user->can($permission->value))->toBeTrue();
    }
});

test('ordinary users and company owners do not receive platform permissions or roles', function () {
    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    expect($user->getRoleNames())->toBeEmpty()
        ->and($user->can(PlatformPermission::PlatformAccess->value))->toBeFalse()
        ->and($user->hasRole('owner'))->toBeFalse();
});

test('super admin has no global gate bypass and cannot enter tenant routes without membership', function () {
    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->actingAs($user);

    expect(Gate::forUser($user)->allows('unrelated.sensitive-operation'))->toBeFalse()
        ->and(Gate::forUser($user)->allows(CompanyPermission::CompanyView->value, $company))->toBeFalse();

    $this->get(route('dashboard'))
        ->assertRedirect(route('companies.none'));
});
