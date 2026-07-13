<?php

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;

test('authorization follows the current company and its fresh membership role', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $companyA,
    ]);
    CompanyMembership::factory()->viewer()->create([
        'user_id' => $user,
        'company_id' => $companyB,
    ]);
    $this->actingAs($user);
    $currentCompany = app(CurrentCompany::class);

    $currentCompany->set($companyA);

    expect(Gate::forUser($user)->allows(CompanyPermission::CompanyUpdate->value, $companyA))->toBeTrue()
        ->and(Gate::forUser($user)->allows(CompanyPermission::CompanyView->value, $companyB))->toBeFalse();

    $currentCompany->set($companyB);

    expect(Gate::forUser($user)->allows(CompanyPermission::CompanyView->value, $companyB))->toBeTrue()
        ->and(Gate::forUser($user)->allows(CompanyPermission::CompanyUpdate->value, $companyB))->toBeFalse()
        ->and(Gate::forUser($user)->allows(CompanyPermission::CompanyUpdate->value, $companyA))->toBeFalse();
});

test('authorization ignores a stale loaded relation and reads a changed role from the database', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->admin()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);
    $user->load('memberships');
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);
    $authorizer = app(CompanyAuthorizer::class);

    expect($authorizer->allows($user, $company, CompanyPermission::CompanyUpdate))->toBeTrue();

    CompanyMembership::query()->whereKey($membership->getKey())->update([
        'role' => CompanyRole::Viewer->value,
        'is_owner' => false,
    ]);

    expect($authorizer->allows($user, $company, CompanyPermission::CompanyUpdate))->toBeFalse()
        ->and($user->relationLoaded('memberships'))->toBeTrue();
});

test('deleted membership and suspended user immediately lose company authorization', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);
    $authorizer = app(CompanyAuthorizer::class);

    $user->status = UserStatus::Suspended;
    $user->save();

    expect($authorizer->allows($user->refresh(), $company, CompanyPermission::CompanyView))->toBeFalse();

    $user->status = UserStatus::Active;
    $user->save();
    $membership->delete();

    expect($authorizer->allows($user->refresh(), $company, CompanyPermission::CompanyView))->toBeFalse();
});

test('platform super admin does not become a tenant member implicitly', function () {
    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    expect(app(CompanyAuthorizer::class)->allows(
        $user,
        $company,
        CompanyPermission::CompanyView,
    ))->toBeFalse();
});
