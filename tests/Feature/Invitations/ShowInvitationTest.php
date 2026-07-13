<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;

function stage6PublicInvitation(array $attributes = []): array
{
    $token = 'public-invitation-token-'.bin2hex(random_bytes(32));
    $invitation = CompanyInvitation::factory()->pending()->create(array_merge([
        'company_id' => Company::factory(),
        'email' => 'invited@example.com',
        'role' => CompanyRole::Editor,
        'token_hash' => hash('sha256', $token),
    ], $attributes));

    return [$invitation, $token];
}

test('valid invitation token shows safe invitation details and security headers', function () {
    [$invitation, $token] = stage6PublicInvitation();

    $response = $this->get(route('invitations.show', [
        'invitation' => $invitation,
        'token' => $token,
    ]));

    $response->assertOk()
        ->assertSee($invitation->company->name)
        ->assertSee('editor')
        ->assertSee('invited@example.com')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Pragma', 'no-cache');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private');
    $response->assertDontSee((string) $invitation->getAttribute('token_hash'))
        ->assertDontSee('fonts.bunny.net', false)
        ->assertDontSee('http://fonts', false)
        ->assertDontSee('https://fonts', false);
});

test('wrong or empty token returns not found without invitation details', function (?string $token) {
    [$invitation] = stage6PublicInvitation();
    $parameters = ['invitation' => $invitation];

    if ($token !== null) {
        $parameters['token'] = $token;
    }

    $this->get(route('invitations.show', $parameters))
        ->assertNotFound()
        ->assertDontSee($invitation->email);
})->with(['wrong token' => 'wrong', 'empty token' => '', 'missing token' => null]);

test('invitation page presents expired accepted and cancelled states', function (string $factoryState, string $message) {
    $token = 'state-token-'.str_repeat('a', 48);
    $invitation = CompanyInvitation::factory()->{$factoryState}()->create([
        'token_hash' => hash('sha256', $token),
    ]);

    $this->get(route('invitations.show', [
        'invitation' => $invitation,
        'token' => $token,
    ]))->assertOk()->assertSee($message)->assertDontSee('Accept invitation');
})->with([
    'expired' => ['expired', 'has expired'],
    'accepted' => ['accepted', 'already accepted'],
    'cancelled' => ['cancelled', 'was cancelled'],
]);

test('guest is directed to login for an existing account and invitation registration otherwise', function () {
    [$existingInvitation, $existingToken] = stage6PublicInvitation(['email' => 'existing@example.com']);
    User::factory()->create(['email' => 'existing@example.com']);

    $this->get(route('invitations.show', [
        'invitation' => $existingInvitation,
        'token' => $existingToken,
    ]))->assertOk()->assertSee('Sign in to continue')->assertSessionHas('url.intended');

    [$newInvitation, $newToken] = stage6PublicInvitation(['email' => 'new@example.com']);

    $this->get(route('invitations.show', [
        'invitation' => $newInvitation,
        'token' => $newToken,
    ]))->assertOk()->assertSee('Create account and continue');
});

test('authenticated wrong account sees safe mismatch state', function () {
    [$invitation, $token] = stage6PublicInvitation(['email' => 'correct@example.com']);
    $this->actingAs(User::factory()->create(['email' => 'wrong@example.com']));

    $this->get(route('invitations.show', [
        'invitation' => $invitation,
        'token' => $token,
    ]))->assertOk()
        ->assertSee('different email address')
        ->assertDontSee('Accept invitation');
});
