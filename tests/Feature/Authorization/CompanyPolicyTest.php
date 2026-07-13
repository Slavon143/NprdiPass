<?php

use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Gate;

test('company policy applies the role matrix to the current company', function (
    CompanyRole $role,
    bool $canUpdate,
) {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user,
        'company_id' => $company,
        'role' => $role,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    expect(Gate::forUser($user)->allows('view', $company))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $company))->toBe($canUpdate);
})->with([
    'owner' => [CompanyRole::Owner, true],
    'admin' => [CompanyRole::Admin, true],
    'editor' => [CompanyRole::Editor, false],
    'viewer' => [CompanyRole::Viewer, false],
]);

test('company policy denies a different company even when the user is an owner elsewhere', function () {
    $user = User::factory()->create();
    $currentCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $currentCompany,
    ]);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $otherCompany,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($currentCompany);

    expect(Gate::forUser($user)->allows('view', $otherCompany))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $otherCompany))->toBeFalse();
});

test('suspended and archived companies cannot be updated in tenant context', function (
    CompanyStatus $status,
) {
    $user = User::factory()->create();
    $company = Company::factory()->create(['status' => $status]);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    expect(Gate::forUser($user)->allows('update', $company))->toBeFalse();
})->with([
    'suspended' => [CompanyStatus::Suspended],
    'archived' => [CompanyStatus::Archived],
]);

test('company policy reads a fresh membership and revokes a deleted membership', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);
    $user->load('memberships');
    $this->actingAs($user);
    app(CurrentCompany::class)->set($company);

    expect(Gate::forUser($user)->allows('update', $company))->toBeTrue();

    CompanyMembership::query()->whereKey($membership->getKey())->update([
        'role' => CompanyRole::Viewer->value,
        'is_owner' => false,
    ]);

    expect(Gate::forUser($user)->allows('update', $company))->toBeFalse();

    $membership->delete();

    expect(Gate::forUser($user)->allows('view', $company))->toBeFalse();
});
