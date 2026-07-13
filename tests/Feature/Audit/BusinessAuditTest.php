<?php

use App\Actions\Companies\AcceptCompanyInvitation;
use App\Actions\Companies\CancelCompanyInvitation;
use App\Actions\Companies\ChangeCompanyMemberRole;
use App\Actions\Companies\InviteCompanyMember;
use App\Actions\Companies\RemoveCompanyMember;
use App\Actions\Companies\ResendCompanyInvitation;
use App\Actions\Companies\UpdateCompany;
use App\Audit\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;

function stage7Actor(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'company_id' => $company,
        'user_id' => $actor,
        'role' => $role,
    ]);

    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company];
}

test('company update records only changed allowlisted fields', function () {
    [$actor, $company] = stage7Actor();
    $oldName = $company->name;
    $oldBillingEmail = $company->billing_email;

    app(UpdateCompany::class)->execute($actor, $company, [
        'name' => 'Updated Company AB',
        'legal_name' => $company->legal_name,
        'billing_email' => 'billing@example.com',
        'status' => 'archived',
        'uuid' => 'not-allowed',
    ]);

    $log = AuditLog::query()->where('event', AuditEvent::CompanyUpdated->value)->sole();
    $changes = $log->getProperty('changes');

    expect($changes)->toHaveKeys(['name', 'billing_email'])
        ->and($changes['name']['old'])->toBe($oldName)
        ->and($changes['name']['new'])->toBe('Updated Company AB')
        ->and($changes['billing_email']['old'])->toBe($oldBillingEmail)
        ->and($changes['billing_email']['new'])->toBe('billing@example.com')
        ->and(json_encode($changes))->not->toContain('status', 'uuid')
        ->and($log->company_id)->toBe($company->getKey());
});

test('audit failure rolls back a company update in the same transaction', function () {
    [$actor, $company] = stage7Actor();
    $originalName = $company->name;
    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('audit unavailable'));
    $this->app->instance(AuditLogger::class, $auditLogger);

    expect(fn () => app(UpdateCompany::class)->execute($actor, $company, [
        'name' => 'Must Roll Back',
    ]))->toThrow(RuntimeException::class, 'audit unavailable');

    expect($company->fresh()?->name)->toBe($originalName)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('role changes and member removals record target snapshots', function () {
    [$actor, $company] = stage7Actor();
    $targetUser = User::factory()->create();
    $target = CompanyMembership::factory()->viewer()->create([
        'company_id' => $company,
        'user_id' => $targetUser,
    ]);

    app(ChangeCompanyMemberRole::class)->execute($actor, $target, CompanyRole::Editor);
    $roleLog = AuditLog::query()->where('event', AuditEvent::MemberRoleChanged->value)->sole();

    expect($roleLog->getProperty('target_user_uuid'))->toBe($targetUser->uuid)
        ->and($roleLog->getProperty('target_email'))->toBe($targetUser->email)
        ->and($roleLog->getProperty('old_role'))->toBe(CompanyRole::Viewer->value)
        ->and($roleLog->getProperty('new_role'))->toBe(CompanyRole::Editor->value);

    app(RemoveCompanyMember::class)->execute($actor, $target);
    $removeLog = AuditLog::query()->where('event', AuditEvent::MemberRemoved->value)->sole();

    expect($removeLog->getProperty('target_user_uuid'))->toBe($targetUser->uuid)
        ->and($removeLog->getProperty('removed_role'))->toBe(CompanyRole::Editor->value)
        ->and($target->fresh())->toBeNull();
});

test('a foreign membership mutation creates no tenant audit event', function () {
    [$actor] = stage7Actor();
    $foreignCompany = Company::factory()->create();
    $foreignMembership = CompanyMembership::factory()->viewer()->create([
        'company_id' => $foreignCompany,
    ]);

    expect(fn () => app(ChangeCompanyMemberRole::class)->execute(
        $actor,
        $foreignMembership,
        CompanyRole::Editor,
    ))->toThrow(AuthorizationException::class);

    expect(AuditLog::query()->count())->toBe(0);
});

test('invitation create resend cancel and accept events contain no token material', function () {
    [$actor, $company] = stage7Actor();
    $inviteAction = app(InviteCompanyMember::class);
    $first = $inviteAction->execute($actor, $company, 'member@example.com', CompanyRole::Viewer);
    $firstToken = $first->plainTextToken();
    $resent = app(ResendCompanyInvitation::class)->execute($actor, $first->invitation());
    $resentToken = $resent->plainTextToken();
    app(CancelCompanyInvitation::class)->execute($actor, $resent->invitation());

    $acceptedUser = User::factory()->create(['email' => 'accepted@example.com']);
    $accepted = $inviteAction->execute(
        $actor,
        $company,
        $acceptedUser->email,
        CompanyRole::Editor,
    );
    app(AcceptCompanyInvitation::class)->execute(
        $accepted->invitation(),
        $acceptedUser,
        $accepted->plainTextToken(),
    );

    expect(AuditLog::query()->where('event', AuditEvent::MemberInvited->value)->count())->toBe(2)
        ->and(AuditLog::query()->where('event', AuditEvent::MemberInvitationResent->value)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::MemberInvitationCancelled->value)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::MemberInvitationAccepted->value)->count())->toBe(1);

    $serialized = AuditLog::query()->get()->pluck('properties')->toJson();
    expect($serialized)->not->toContain(
        $firstToken,
        $resentToken,
        $accepted->plainTextToken(),
        hash('sha256', $firstToken),
        'token_hash',
        'accept_url',
    )->and($serialized)->toContain('accepted@example.com', CompanyRole::Editor->value);
});

test('audit failure rolls back invitation creation and its secret record', function () {
    [$actor, $company] = stage7Actor();
    $auditLogger = Mockery::mock(AuditLogger::class);
    $auditLogger->shouldReceive('logTenant')->once()->andThrow(new RuntimeException('audit unavailable'));
    $this->app->instance(AuditLogger::class, $auditLogger);

    expect(fn () => app(InviteCompanyMember::class)->execute(
        $actor,
        $company,
        'rollback@example.com',
        CompanyRole::Viewer,
    ))->toThrow(RuntimeException::class);

    $this->assertDatabaseMissing('company_invitations', ['email' => 'rollback@example.com']);
    expect(AuditLog::query()->count())->toBe(0);
});
