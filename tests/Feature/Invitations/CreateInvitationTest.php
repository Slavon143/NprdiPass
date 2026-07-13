<?php

use App\Actions\Companies\InviteCompanyMember;
use App\Domain\Invitations\Exceptions\CompanyMemberAlreadyExists;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

function stage6Actor(CompanyRole $role): array
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

test('owner can invite every company role', function (CompanyRole $role) {
    [$actor, $company] = stage6Actor(CompanyRole::Owner);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $pending = app(InviteCompanyMember::class)->execute(
        $actor,
        $company,
        'new.member@example.com',
        $role,
    );

    expect($pending->invitation()->role)->toBe($role)
        ->and($pending->invitation()->isPending())->toBeTrue();
})->with(CompanyRole::cases());

test('admin can invite non owners but cannot invite an owner', function () {
    [$actor, $company] = stage6Actor(CompanyRole::Admin);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $invitation = app(InviteCompanyMember::class)->execute(
        $actor,
        $company,
        'editor@example.com',
        CompanyRole::Editor,
    )->invitation();

    expect($invitation->role)->toBe(CompanyRole::Editor);

    expect(fn () => app(InviteCompanyMember::class)->execute(
        $actor,
        $company,
        'owner@example.com',
        CompanyRole::Owner,
    ))->toThrow(AuthorizationException::class);
});

test('editor and viewer cannot create invitations', function (CompanyRole $role) {
    [$actor, $company] = stage6Actor($role);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(InviteCompanyMember::class)->execute(
        $actor,
        $company,
        'blocked@example.com',
        CompanyRole::Viewer,
    ))->toThrow(AuthorizationException::class);
})->with([CompanyRole::Editor, CompanyRole::Viewer]);

test('invitation creation normalizes email and stores only a sha256 hash', function () {
    config()->set('invitations.expires_hours', 24);
    [$actor, $company] = stage6Actor(CompanyRole::Owner);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $before = now();

    $pending = app(InviteCompanyMember::class)->execute(
        $actor,
        $company,
        '  Mixed.Case+alias@Example.COM  ',
        CompanyRole::Viewer,
    );
    $invitation = $pending->invitation();

    expect($invitation->email)->toBe('mixed.case+alias@example.com')
        ->and($invitation->getAttribute('invited_by'))->toBe($actor->getKey())
        ->and($invitation->getAttribute('token_hash'))->toBe(hash('sha256', $pending->plainTextToken()))
        ->and($invitation->expires_at->between(
            $before->copy()->startOfSecond()->addHours(24),
            now()->endOfSecond()->addHours(24),
        ))->toBeTrue()
        ->and($invitation->toArray())->not->toHaveKey('token_hash')
        ->and($invitation->getAttributes())->not->toHaveKey('plain_text_token')
        ->and(json_encode($pending))->toBe('{}');

    $this->assertDatabaseMissing('company_invitations', [
        'token_hash' => $pending->plainTextToken(),
    ]);
});

test('an existing company member cannot be invited', function () {
    [$actor, $company] = stage6Actor(CompanyRole::Owner);
    $member = User::factory()->create(['email' => 'member@example.com']);
    CompanyMembership::factory()->viewer()->create([
        'company_id' => $company,
        'user_id' => $member,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(InviteCompanyMember::class)->execute(
        $actor,
        $company,
        ' MEMBER@example.com ',
        CompanyRole::Viewer,
    ))->toThrow(CompanyMemberAlreadyExists::class);

    expect(CompanyInvitation::query()->count())->toBe(0);
});

test('an existing system user without a company membership can be invited', function () {
    [$actor, $company] = stage6Actor(CompanyRole::Owner);
    User::factory()->create(['email' => 'existing@example.com']);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $invitation = app(InviteCompanyMember::class)->execute(
        $actor,
        $company,
        'existing@example.com',
        CompanyRole::Admin,
    )->invitation();

    expect($invitation->isPending())->toBeTrue();
});

test('invitation action refuses a company outside current tenant context', function () {
    [$actor, $currentCompany] = stage6Actor(CompanyRole::Owner);
    $foreignCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $foreignCompany,
        'user_id' => $actor,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($currentCompany);

    expect(fn () => app(InviteCompanyMember::class)->execute(
        $actor,
        $foreignCompany,
        'foreign@example.com',
        CompanyRole::Viewer,
    ))->toThrow(AuthorizationException::class);
});

test('store route ignores protected invitation fields and queues notification after commit', function () {
    Notification::fake();
    [$actor, $company] = stage6Actor(CompanyRole::Owner);
    $foreignCompany = Company::factory()->create();
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $this->post(route('settings.members.invitations.store'), [
        'email' => 'route@example.com',
        'role' => CompanyRole::Viewer->value,
        'company_id' => $foreignCompany->getKey(),
        'invited_by' => User::factory()->create()->getKey(),
        'token_hash' => str_repeat('a', 64),
        'accepted_at' => now(),
        'expires_at' => now()->addYear(),
    ])->assertRedirect()->assertSessionHas('success');

    $invitation = CompanyInvitation::query()->sole();
    expect($invitation->getAttribute('company_id'))->toBe($company->getKey())
        ->and($invitation->getAttribute('invited_by'))->toBe($actor->getKey())
        ->and($invitation->getAttribute('token_hash'))->not->toBe(str_repeat('a', 64))
        ->and($invitation->getAttribute('accepted_at'))->toBeNull();

    Notification::assertSentOnDemand(
        CompanyInvitationNotification::class,
        function (
            CompanyInvitationNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool {
            return $notification instanceof ShouldQueue
                && $notification->afterCommit === true
                && $channels === ['mail']
                && $notifiable->routeNotificationFor('mail') === 'route@example.com';
        },
    );
});

test('admin owner role is rejected by form validation', function () {
    [$actor, $company] = stage6Actor(CompanyRole::Admin);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $this->from(route('settings.members.index'))
        ->post(route('settings.members.invitations.store'), [
            'email' => 'owner@example.com',
            'role' => CompanyRole::Owner->value,
        ])
        ->assertRedirect(route('settings.members.index'))
        ->assertSessionHasErrors('role');

    expect(CompanyInvitation::query()->count())->toBe(0);
});
