<?php

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;

test('company casts status and settings', function () {
    $company = Company::factory()->suspended()->create([
        'settings' => ['locale' => 'sv', 'timezone' => 'Europe/Stockholm'],
    ]);

    expect($company->status)->toBe(CompanyStatus::Suspended)
        ->and($company->settings)->toBe([
            'locale' => 'sv',
            'timezone' => 'Europe/Stockholm',
        ]);
});

test('company supports soft deletes', function () {
    $company = Company::factory()->create();

    $company->delete();

    $this->assertSoftDeleted($company);
});

test('company exposes users memberships and invitations', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $membership = CompanyMembership::factory()->editor()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
    ]);
    $invitation = CompanyInvitation::factory()->create([
        'company_id' => $company->id,
        'invited_by' => $user->id,
    ]);

    expect($company->users()->first()->is($user))->toBeTrue()
        ->and($company->memberships()->first()->is($membership))->toBeTrue()
        ->and($company->invitations()->first()->is($invitation))->toBeTrue();
});
