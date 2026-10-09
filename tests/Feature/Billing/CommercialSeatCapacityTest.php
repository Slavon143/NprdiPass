<?php

use App\Actions\Companies\AcceptCompanyInvitation;
use App\Actions\Companies\CancelCompanyInvitation;
use App\Actions\Companies\CreateCompanyForOwner;
use App\Actions\Companies\InviteCompanyMember;
use App\Actions\Companies\ResendCompanyInvitation;
use App\Enums\CompanyRole;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans')]);
    Notification::fake();
    $this->actor = User::factory()->create();
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Seat limits');
    $this->actingAs($this->actor);
    app(CurrentCompany::class)->set($this->company);
});

test('owner and pending invitations consume seats before invitation acceptance', function () {
    $inviter = app(InviteCompanyMember::class);
    $inviter->execute($this->actor, $this->company, 'first@example.test', CompanyRole::Editor);
    expect(fn () => $inviter->execute($this->actor, $this->company, 'second@example.test', CompanyRole::Viewer))
        ->toThrow(ValidationException::class, 'members.max');
    expect($this->company->memberships()->count())->toBe(1)
        ->and($this->company->invitations()->whereNull('cancelled_at')->count())->toBe(1);
});

test('resending replaces one reservation without consuming another seat', function () {
    $first = app(InviteCompanyMember::class)->execute($this->actor, $this->company, 'first@example.test', CompanyRole::Editor);
    $replacement = app(ResendCompanyInvitation::class)->execute($this->actor, $first->invitation());
    expect($first->invitation()->fresh()->cancelled_at)->not->toBeNull()
        ->and($replacement->invitation()->cancelled_at)->toBeNull()
        ->and($this->company->invitations()->whereNull('cancelled_at')->count())->toBe(1);
});

test('accepting an invitation converts its reserved seat into a membership exactly once', function () {
    $user = User::factory()->create();
    $pending = app(InviteCompanyMember::class)->execute($this->actor, $this->company, $user->email, CompanyRole::Editor);
    app(AcceptCompanyInvitation::class)->execute($pending->invitation(), $user, $pending->plainTextToken());
    expect($this->company->memberships()->count())->toBe(2)
        ->and($pending->invitation()->fresh()->accepted_at)->not->toBeNull();
    expect(fn () => app(InviteCompanyMember::class)->execute($this->actor, $this->company, 'third@example.test', CompanyRole::Viewer))
        ->toThrow(ValidationException::class);
});

test('canceling or expiration releases a reservation without deleting membership data', function () {
    $inviter = app(InviteCompanyMember::class);
    $first = $inviter->execute($this->actor, $this->company, 'first@example.test', CompanyRole::Editor);
    app(CancelCompanyInvitation::class)->execute($this->actor, $first->invitation());
    $second = $inviter->execute($this->actor, $this->company, 'second@example.test', CompanyRole::Editor);
    $second->invitation()->update(['expires_at' => now()->subSecond()]);
    $inviter->execute($this->actor, $this->company, 'third@example.test', CompanyRole::Viewer);
    expect($this->company->memberships()->count())->toBe(1);
});

test('acceptance rechecks a downgraded allowance and preserves an unaccepted invitation', function () {
    $user = User::factory()->create();
    $pending = app(InviteCompanyMember::class)->execute($this->actor, $this->company, $user->email, CompanyRole::Editor);
    $this->company->subscription()->firstOrFail()->update(['entitlements' => ['members.max' => 1]]);
    expect(fn () => app(AcceptCompanyInvitation::class)->execute($pending->invitation(), $user, $pending->plainTextToken()))
        ->toThrow(ValidationException::class);
    expect($pending->invitation()->fresh()->accepted_at)->toBeNull()
        ->and($this->company->memberships()->count())->toBe(1);
});

test('acceptance after trial expiration cannot add a membership or consume the invitation', function () {
    $user = User::factory()->create();
    $pending = app(InviteCompanyMember::class)->execute($this->actor, $this->company, $user->email, CompanyRole::Editor);
    $this->company->subscription()->firstOrFail()->update(['trial_ends_at' => now()->subSecond()]);
    expect(fn () => app(AcceptCompanyInvitation::class)->execute($pending->invitation(), $user, $pending->plainTextToken()))
        ->toThrow(ValidationException::class);
    expect($pending->invitation()->fresh()->accepted_at)->toBeNull()
        ->and($this->company->memberships()->count())->toBe(1);
});
