<?php

use App\Actions\Companies\AcceptCompanyInvitation;
use App\Domain\Invitations\Exceptions\InvitationCannotBeAccepted;
use App\Enums\CompanyRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;

function stage6AcceptanceInvitation(User $user, CompanyRole $role = CompanyRole::Viewer, array $attributes = []): array
{
    $token = 'acceptance-token-'.bin2hex(random_bytes(32));
    $invitation = CompanyInvitation::factory()->pending()->create(array_merge([
        'company_id' => Company::factory(),
        'email' => $user->email,
        'role' => $role,
        'token_hash' => hash('sha256', $token),
    ], $attributes));

    return [$invitation, $token];
}

test('matching authenticated user accepts invitation and switches current company', function (CompanyRole $role, bool $isOwner) {
    $user = User::factory()->unverified()->create(['email' => 'accept@example.com']);
    [$invitation, $token] = stage6AcceptanceInvitation($user, $role);
    $this->actingAs($user);

    $this->post(route('invitations.accept', ['invitation' => $invitation]), [
        'token' => $token,
        'role' => CompanyRole::Owner->value,
        'is_owner' => ! $isOwner,
    ])->assertRedirect(route('dashboard'))
        ->assertSessionHas(config('tenancy.session_key'), $invitation->getAttribute('company_id'));

    $membership = CompanyMembership::query()->sole();
    expect($membership->role)->toBe($role)
        ->and($membership->is_owner)->toBe($isOwner)
        ->and($membership->joined_at)->not->toBeNull()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
})->with([
    'owner' => [CompanyRole::Owner, true],
    'viewer' => [CompanyRole::Viewer, false],
]);

test('acceptance requires matching email and active user status', function () {
    $matchingUser = User::factory()->create(['email' => 'correct@example.com']);
    [$invitation, $token] = stage6AcceptanceInvitation($matchingUser);
    $wrongUser = User::factory()->create(['email' => 'wrong@example.com']);
    $this->actingAs($wrongUser);

    $this->post(route('invitations.accept', ['invitation' => $invitation]), [
        'token' => $token,
    ])->assertRedirect(route('invitations.show', [
        'invitation' => $invitation,
        'token' => $token,
    ]))->assertSessionHas('error');

    expect(CompanyMembership::query()->count())->toBe(0)
        ->and($invitation->fresh()->accepted_at)->toBeNull();

    $suspended = User::factory()->suspended()->create(['email' => 'suspended@example.com']);
    [$suspendedInvitation, $suspendedToken] = stage6AcceptanceInvitation($suspended);
    $this->actingAs($suspended);

    $this->post(route('invitations.accept', ['invitation' => $suspendedInvitation]), [
        'token' => $suspendedToken,
    ])->assertSessionHas('error');

    expect(CompanyMembership::query()->count())->toBe(0);
});

test('invited status becomes active after successful acceptance', function () {
    $user = User::factory()->invited()->create(['email' => 'invited-status@example.com']);
    [$invitation, $token] = stage6AcceptanceInvitation($user);

    app(AcceptCompanyInvitation::class)->execute($invitation, $user, $token);

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

test('wrong token expired cancelled and accepted invitations cannot be accepted', function (string $state) {
    $user = User::factory()->create(['email' => "{$state}@example.com"]);
    [$invitation, $token] = stage6AcceptanceInvitation($user);

    if ($state === 'expired') {
        $invitation->expires_at = now()->subMinute();
        $invitation->save();
    } elseif ($state === 'cancelled') {
        $invitation->setAttribute('cancelled_at', now());
        $invitation->save();
    } elseif ($state === 'accepted') {
        $invitation->setAttribute('accepted_at', now());
        $invitation->save();
    }

    $providedToken = $state === 'wrong' ? 'incorrect-token' : $token;

    expect(fn () => app(AcceptCompanyInvitation::class)->execute(
        $invitation,
        $user,
        $providedToken,
    ))->toThrow(InvitationCannotBeAccepted::class);

    expect(CompanyMembership::query()->count())->toBe(0);
})->with(['wrong', 'expired', 'cancelled', 'accepted']);

test('accepted invitation cannot be used twice and duplicate membership is never created', function () {
    $user = User::factory()->create(['email' => 'once@example.com']);
    [$invitation, $token] = stage6AcceptanceInvitation($user);
    $action = app(AcceptCompanyInvitation::class);

    $action->execute($invitation, $user, $token);

    expect(fn () => $action->execute($invitation, $user, $token))
        ->toThrow(InvitationCannotBeAccepted::class)
        ->and(CompanyMembership::query()->count())->toBe(1);
});

test('existing membership blocks acceptance without changing invitation', function () {
    $user = User::factory()->create(['email' => 'member@example.com']);
    [$invitation, $token] = stage6AcceptanceInvitation($user);
    CompanyMembership::factory()->viewer()->create([
        'company_id' => $invitation->getAttribute('company_id'),
        'user_id' => $user,
    ]);

    expect(fn () => app(AcceptCompanyInvitation::class)->execute($invitation, $user, $token))
        ->toThrow(InvitationCannotBeAccepted::class)
        ->and(CompanyMembership::query()->count())->toBe(1)
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});
