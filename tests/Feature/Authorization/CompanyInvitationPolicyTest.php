<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Policies\CompanyInvitationPolicy;
use App\Tenancy\Contracts\CurrentCompany;

test('invitation policy allows owners and admins but denies editor and viewer', function (
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
    $invitation = CompanyInvitation::factory()->create(['company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $policy = app(CompanyInvitationPolicy::class);

    expect($policy->viewAny($actor, $company))->toBe($expected)
        ->and($policy->create($actor, $company))->toBe($expected)
        ->and($policy->delete($actor, $invitation))->toBe($expected);
})->with([
    'owner' => [CompanyRole::Owner, true],
    'admin' => [CompanyRole::Admin, true],
    'editor' => [CompanyRole::Editor, false],
    'viewer' => [CompanyRole::Viewer, false],
]);

test('invitation policy denies an invitation from another company', function () {
    $actor = User::factory()->create();
    $currentCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $actor,
        'company_id' => $currentCompany,
    ]);
    $invitation = CompanyInvitation::factory()->create(['company_id' => $otherCompany]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($currentCompany);

    expect(app(CompanyInvitationPolicy::class)->delete($actor, $invitation))->toBeFalse();
});
