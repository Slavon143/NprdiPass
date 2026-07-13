<?php

use App\Enums\CompanyRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Carbon;

test('user casts status and last login timestamp', function () {
    $user = User::factory()->suspended()->create([
        'last_login_at' => now(),
    ]);

    expect($user->status)->toBe(UserStatus::Suspended)
        ->and($user->last_login_at)->toBeInstanceOf(Carbon::class);
});

test('user supports soft deletes', function () {
    $user = User::factory()->create();

    $user->delete();

    $this->assertSoftDeleted($user);
});

test('user exposes company membership and sent invitation relationships', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->admin()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
    ]);
    $invitation = CompanyInvitation::factory()->create([
        'company_id' => $company->id,
        'invited_by' => $user->id,
    ]);

    expect($user->companies()->first()->is($company))->toBeTrue()
        ->and($user->memberships()->first()->is($membership))->toBeTrue()
        ->and($user->memberships()->first()->role)->toBe(CompanyRole::Admin)
        ->and($user->invitationsSent()->first()->is($invitation))->toBeTrue();
});
