<?php

use App\Actions\Companies\RegisterFromCompanyInvitation;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function stage6RegistrationInvitation(array $attributes = []): array
{
    $token = 'registration-token-'.str_repeat('r', 48);
    $invitation = CompanyInvitation::factory()->pending()->create(array_merge([
        'company_id' => Company::factory(),
        'email' => 'new.user@example.com',
        'role' => CompanyRole::Editor,
        'token_hash' => hash('sha256', $token),
    ], $attributes));

    return [$invitation, $token];
}

test('guest with valid invitation can open restricted registration form', function () {
    [$invitation, $token] = stage6RegistrationInvitation();

    $this->get(route('invitations.register', [
        'invitation' => $invitation,
        'token' => $token,
    ]))->assertOk()
        ->assertSee('new.user@example.com')
        ->assertSee('Create your NordiPass account')
        ->assertDontSee('name="email"', false)
        ->assertHeader('Referrer-Policy', 'no-referrer');
});

test('invitation registration creates verified user membership and authenticated tenant session', function () {
    [$invitation, $token] = stage6RegistrationInvitation(['role' => CompanyRole::Owner]);

    $this->post(route('invitations.register', ['invitation' => $invitation]), [
        'token' => $token,
        'name' => 'Invited Person',
        'email' => 'attacker@example.com',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
    ])->assertRedirect(route('dashboard'))
        ->assertSessionHas(config('tenancy.session_key'), $invitation->getAttribute('company_id'));

    $user = User::query()->where('email', 'new.user@example.com')->sole();
    $membership = CompanyMembership::query()->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull()
        ->and(User::query()->where('email', 'attacker@example.com')->exists())->toBeFalse()
        ->and($membership->user_id)->toBe($user->getKey())
        ->and($membership->role)->toBe(CompanyRole::Owner)
        ->and($membership->is_owner)->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('weak password is rejected without partial records', function () {
    [$invitation, $token] = stage6RegistrationInvitation();

    $this->from(route('invitations.register', [
        'invitation' => $invitation,
        'token' => $token,
    ]))->post(route('invitations.register', ['invitation' => $invitation]), [
        'token' => $token,
        'name' => 'Weak Password',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
    expect(User::query()->where('email', $invitation->email)->exists())->toBeFalse()
        ->and(CompanyMembership::query()->count())->toBe(0)
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('existing email is directed to sign in and never creates duplicate user', function () {
    [$invitation, $token] = stage6RegistrationInvitation(['email' => 'existing@example.com']);
    User::factory()->create(['email' => 'existing@example.com']);

    $this->get(route('invitations.register', [
        'invitation' => $invitation,
        'token' => $token,
    ]))->assertRedirect(route('invitations.show', [
        'invitation' => $invitation,
        'token' => $token,
    ]));

    $this->post(route('invitations.register', ['invitation' => $invitation]), [
        'token' => $token,
        'name' => 'Duplicate',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
    ])->assertSessionHasErrors('invitation');

    expect(User::query()->where('email', 'existing@example.com')->count())->toBe(1)
        ->and(CompanyMembership::query()->count())->toBe(0);
});

test('wrong token and unavailable invitation cannot register', function (string $state) {
    [$invitation, $token] = stage6RegistrationInvitation();

    if ($state !== 'wrong') {
        $invitation->forceFill([
            $state === 'expired' ? 'expires_at' : 'cancelled_at' => $state === 'expired' ? now()->subMinute() : now(),
        ])->save();
    }

    $providedToken = $state === 'wrong' ? 'wrong-token' : $token;
    $response = $this->get(route('invitations.register', [
        'invitation' => $invitation,
        'token' => $providedToken,
    ]));

    $state === 'wrong' ? $response->assertNotFound() : $response->assertRedirect();
    expect(User::query()->where('email', $invitation->email)->exists())->toBeFalse();
})->with(['wrong', 'expired', 'cancelled']);

test('registration transaction rolls back user when membership acceptance fails', function () {
    [$invitation, $token] = stage6RegistrationInvitation();
    DB::table('company_invitations')->where('id', $invitation->getKey())->update(['role' => 'invalid-role']);
    $invitation->refresh();

    expect(fn () => app(RegisterFromCompanyInvitation::class)->execute(
        $invitation,
        $token,
        'Rollback User',
        'secure-password',
    ))->toThrow(ValueError::class);

    expect(User::query()->where('email', 'new.user@example.com')->exists())->toBeFalse()
        ->and(CompanyMembership::query()->count())->toBe(0)
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});
