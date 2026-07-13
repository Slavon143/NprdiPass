<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Policies\CompanyMemberPolicy;
use App\Tenancy\Contracts\CurrentCompany;

test('member policy controls listing with the company permission matrix', function (
    CompanyRole $role,
    bool $expected,
) {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $actor,
        'company_id' => $company,
        'role' => $role,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(app(CompanyMemberPolicy::class)->viewAny($actor, $company))->toBe($expected);
})->with([
    'owner' => [CompanyRole::Owner, true],
    'admin' => [CompanyRole::Admin, true],
    'editor' => [CompanyRole::Editor, true],
    'viewer' => [CompanyRole::Viewer, false],
]);

test('member policy denies viewing a membership from another company', function () {
    $actor = User::factory()->create();
    $currentCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $currentCompany,
    ]);
    $foreignMembership = CompanyMembership::factory()->create([
        'company_id' => $otherCompany,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($currentCompany);

    expect(app(CompanyMemberPolicy::class)->view($actor, $foreignMembership))->toBeFalse();
});

test('member policy lets admins manage non owners but never owners', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->admin()->create([
        'user_id' => $actor,
        'company_id' => $company,
    ]);
    $owner = CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    $editor = CompanyMembership::factory()->editor()->create(['company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $policy = app(CompanyMemberPolicy::class);

    expect($policy->updateRole($actor, $editor))->toBeTrue()
        ->and($policy->remove($actor, $editor))->toBeTrue()
        ->and($policy->updateRole($actor, $owner))->toBeFalse()
        ->and($policy->remove($actor, $owner))->toBeFalse();
});

test('member policy allows owners to manage members but rejects admin self removal', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $owner,
        'company_id' => $company,
    ]);
    $adminMembership = CompanyMembership::factory()->admin()->create([
        'user_id' => $admin,
        'company_id' => $company,
    ]);

    $this->actingAs($owner);
    app(CurrentCompany::class)->set($company);
    expect(app(CompanyMemberPolicy::class)->updateRole($owner, $adminMembership))->toBeTrue()
        ->and(app(CompanyMemberPolicy::class)->remove($owner, $adminMembership))->toBeTrue();

    $this->actingAs($admin);
    app(CurrentCompany::class)->set($company);
    expect(app(CompanyMemberPolicy::class)->remove($admin, $adminMembership))->toBeFalse();
});
