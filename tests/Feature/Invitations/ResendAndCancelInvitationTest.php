<?php

use App\Actions\Companies\CancelCompanyInvitation;
use App\Actions\Companies\InviteCompanyMember;
use App\Actions\Companies\ResendCompanyInvitation;
use App\Domain\Invitations\Exceptions\InvitationCannotBeCancelled;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Security\InvitationTokenVerifier;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;

function stage6ManagementActor(CompanyRole $role): array
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

function stage6Invitation(Company $company, User $inviter, CompanyRole $role = CompanyRole::Viewer): array
{
    $token = 'stage6-secure-token-'.str_repeat('x', 48);
    $invitation = CompanyInvitation::factory()->pending()->create([
        'company_id' => $company,
        'invited_by' => $inviter,
        'role' => $role,
        'token_hash' => hash('sha256', $token),
    ]);

    return [$invitation, $token];
}

test('second invitation cancels old pending link and preserves history', function () {
    [$actor, $company] = stage6ManagementActor(CompanyRole::Owner);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $action = app(InviteCompanyMember::class);

    $first = $action->execute($actor, $company, 'duplicate@example.com', CompanyRole::Viewer);
    $second = $action->execute($actor, $company, 'DUPLICATE@example.com', CompanyRole::Admin);

    $firstInvitation = $first->invitation()->fresh();
    expect($firstInvitation->isCancelled())->toBeTrue()
        ->and($firstInvitation->isPending())->toBeFalse()
        ->and(app(InvitationTokenVerifier::class)->verify($firstInvitation, $first->plainTextToken()))->toBeTrue()
        ->and($second->invitation()->isPending())->toBeTrue()
        ->and($second->invitation()->uuid)->not->toBe($firstInvitation->uuid)
        ->and($second->plainTextToken())->not->toBe($first->plainTextToken())
        ->and(CompanyInvitation::query()->count())->toBe(2)
        ->and(CompanyInvitation::query()->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '>', now())->count())->toBe(1);

    $this->get(route('invitations.show', [
        'invitation' => $firstInvitation,
        'token' => $first->plainTextToken(),
    ]))->assertOk()->assertSee('cancelled');
});

test('resend creates a new invitation and invalidates the old invitation state', function () {
    [$actor, $company] = stage6ManagementActor(CompanyRole::Owner);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    [$oldInvitation, $oldToken] = stage6Invitation($company, $actor);

    $replacement = app(ResendCompanyInvitation::class)->execute($actor, $oldInvitation);

    expect($oldInvitation->fresh()->isCancelled())->toBeTrue()
        ->and($replacement->invitation()->isPending())->toBeTrue()
        ->and($replacement->invitation()->uuid)->not->toBe($oldInvitation->uuid)
        ->and($replacement->plainTextToken())->not->toBe($oldToken)
        ->and($replacement->invitation()->getAttribute('token_hash'))->not->toBe($oldInvitation->getAttribute('token_hash'));
});

test('resend route scopes invitation lookup to current company', function () {
    [$actor, $company] = stage6ManagementActor(CompanyRole::Owner);
    $foreignCompany = Company::factory()->create();
    [$foreignInvitation] = stage6Invitation($foreignCompany, $actor);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $this->post(route('settings.members.invitations.resend', [
        'invitation' => $foreignInvitation->uuid,
    ]))->assertNotFound();

    expect($foreignInvitation->fresh()->isPending())->toBeTrue();
});

test('admin cannot resend or cancel an owner invitation', function () {
    [$actor, $company] = stage6ManagementActor(CompanyRole::Admin);
    [$invitation] = stage6Invitation($company, $actor, CompanyRole::Owner);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(ResendCompanyInvitation::class)->execute($actor, $invitation))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(CancelCompanyInvitation::class)->execute($actor, $invitation))
        ->toThrow(AuthorizationException::class);
});

test('owner and admin can cancel permitted pending invitations without deleting history', function (CompanyRole $role) {
    [$actor, $company] = stage6ManagementActor($role);
    [$invitation, $token] = stage6Invitation($company, $actor, CompanyRole::Viewer);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    app(CancelCompanyInvitation::class)->execute($actor, $invitation);

    expect($invitation->exists)->toBeTrue()
        ->and($invitation->isCancelled())->toBeTrue()
        ->and($invitation->isPending())->toBeFalse()
        ->and(CompanyInvitation::query()->whereKey($invitation)->exists())->toBeTrue();

    $this->get(route('invitations.show', [
        'invitation' => $invitation,
        'token' => $token,
    ]))->assertOk()->assertSee('cancelled');
})->with([CompanyRole::Owner, CompanyRole::Admin]);

test('editor and viewer cannot cancel invitations', function (CompanyRole $role) {
    [$actor, $company] = stage6ManagementActor($role);
    [$invitation] = stage6Invitation($company, $actor);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(fn () => app(CancelCompanyInvitation::class)->execute($actor, $invitation))
        ->toThrow(AuthorizationException::class);
})->with([CompanyRole::Editor, CompanyRole::Viewer]);

test('cancel route scopes lookup and cannot cancel accepted invitation', function () {
    [$actor, $company] = stage6ManagementActor(CompanyRole::Owner);
    $foreignCompany = Company::factory()->create();
    [$foreignInvitation] = stage6Invitation($foreignCompany, $actor);
    $accepted = CompanyInvitation::factory()->accepted()->create([
        'company_id' => $company,
        'invited_by' => $actor,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $this->delete(route('settings.members.invitations.destroy', [
        'invitation' => $foreignInvitation->uuid,
    ]))->assertNotFound();

    expect(fn () => app(CancelCompanyInvitation::class)->execute($actor, $accepted))
        ->toThrow(InvitationCannotBeCancelled::class);
});
