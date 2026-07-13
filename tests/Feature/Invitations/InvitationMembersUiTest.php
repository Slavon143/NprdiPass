<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Notification;

function stage6UiActor(CompanyRole $role): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'company_id' => $company,
        'user_id' => $actor,
        'role' => $role,
    ]);

    return [$actor, $company];
}

test('owner sees tenant scoped invitation form history and actions without secrets', function () {
    [$actor, $company] = stage6UiActor(CompanyRole::Owner);
    $pending = CompanyInvitation::factory()->pending()->create([
        'company_id' => $company,
        'email' => 'pending@example.com',
        'invited_by' => $actor,
    ]);
    $accepted = CompanyInvitation::factory()->accepted()->create([
        'company_id' => $company,
        'email' => 'accepted@example.com',
        'invited_by' => $actor,
    ]);
    $foreign = CompanyInvitation::factory()->pending()->create([
        'email' => 'foreign@example.com',
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $response = $this->get(route('settings.members.index'));

    $response->assertOk()
        ->assertSee('Invite a company member')
        ->assertSee('pending@example.com')
        ->assertSee('accepted@example.com')
        ->assertSee('Resend')
        ->assertSee('Cancel')
        ->assertDontSee('foreign@example.com')
        ->assertDontSee((string) $pending->getAttribute('token_hash'))
        ->assertDontSee((string) $accepted->getAttribute('token_hash'))
        ->assertDontSee((string) $foreign->getAttribute('token_hash'));
});

test('admin invite form excludes owner while editor and viewer cannot manage invitations', function () {
    [$admin, $adminCompany] = stage6UiActor(CompanyRole::Admin);
    $this->actingAs($admin);
    app(CurrentCompany::class)->set($adminCompany);

    $adminResponse = $this->get(route('settings.members.index'));
    $adminResponse->assertOk()->assertSee('Invite a company member');
    expect(substr_count($adminResponse->getContent(), 'value="owner"'))->toBe(0);

    foreach ([CompanyRole::Editor, CompanyRole::Viewer] as $role) {
        [$actor, $company] = stage6UiActor($role);
        $this->actingAs($actor);
        app(CurrentCompany::class)->set($company);
        $response = $this->get(route('settings.members.index'));

        if ($role === CompanyRole::Editor) {
            $response->assertOk()->assertDontSee('Invite a company member');
        } else {
            $response->assertForbidden();
        }
    }
});

test('invitation management and token verification routes are rate limited', function () {
    Notification::fake();
    [$actor, $company] = stage6UiActor(CompanyRole::Owner);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->post(route('settings.members.invitations.store'), [
            'email' => "rate-{$attempt}@example.com",
            'role' => CompanyRole::Viewer->value,
        ])->assertRedirect();
    }

    $this->post(route('settings.members.invitations.store'), [
        'email' => 'rate-blocked@example.com',
        'role' => CompanyRole::Viewer->value,
    ])->assertTooManyRequests();

    $token = 'verify-rate-token-'.str_repeat('v', 48);
    $invitation = CompanyInvitation::factory()->pending()->create([
        'token_hash' => hash('sha256', $token),
    ]);

    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $this->get(route('invitations.show', [
            'invitation' => $invitation,
            'token' => $token,
        ]))->assertOk();
    }

    $this->get(route('invitations.show', [
        'invitation' => $invitation,
        'token' => $token,
    ]))->assertTooManyRequests();
});
