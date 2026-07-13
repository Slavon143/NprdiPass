<?php

use App\Actions\Companies\AcceptCompanyInvitation;
use App\Actions\Companies\InviteCompanyMember;
use App\Domain\Invitations\Exceptions\InvitationCannotBeAccepted;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('mysql serializes invitation races and enforces acceptance invariants', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');

    $this->artisan('migrate:fresh')->assertSuccessful();

    $competitorConfig = config('database.connections.mysql');
    config()->set('database.connections.mysql_invitation_competitor', $competitorConfig);
    DB::purge('mysql_invitation_competitor');
    $primary = DB::connection('mysql');
    $competitor = DB::connection('mysql_invitation_competitor');
    $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $actor,
    ]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    $primary->beginTransaction();
    try {
        Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
        $competitor->beginTransaction();

        expect(fn () => $competitor->select(
            'select id from companies where id = ? for update',
            [$company->getKey()],
        ))->toThrow(QueryException::class);
    } finally {
        if ($competitor->transactionLevel() > 0) {
            $competitor->rollBack();
        }
        $primary->rollBack();
    }

    $inviteAction = app(InviteCompanyMember::class);
    $first = $inviteAction->execute(
        $actor,
        $company,
        'race@example.com',
        CompanyRole::Viewer,
    );
    $second = $inviteAction->execute(
        $actor,
        $company,
        'RACE@example.com',
        CompanyRole::Editor,
    );

    expect($first->invitation()->fresh()->isCancelled())->toBeTrue()
        ->and($second->invitation()->isPending())->toBeTrue()
        ->and(CompanyInvitation::query()
            ->where('company_id', $company->getKey())
            ->whereNull('accepted_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->count())->toBe(1);

    $primary->beginTransaction();
    try {
        CompanyInvitation::query()
            ->whereKey($second->invitation())
            ->lockForUpdate()
            ->firstOrFail();
        $competitor->beginTransaction();

        expect(fn () => $competitor->select(
            'select id from company_invitations where id = ? for update',
            [$second->invitation()->getKey()],
        ))->toThrow(QueryException::class);
    } finally {
        if ($competitor->transactionLevel() > 0) {
            $competitor->rollBack();
        }
        $primary->rollBack();
    }

    $invitedUser = User::factory()->create(['email' => 'race@example.com']);
    $acceptAction = app(AcceptCompanyInvitation::class);
    $membership = $acceptAction->execute(
        $second->invitation(),
        $invitedUser,
        $second->plainTextToken(),
    );

    expect(fn () => $acceptAction->execute(
        $second->invitation(),
        $invitedUser,
        $second->plainTextToken(),
    ))->toThrow(InvitationCannotBeAccepted::class)
        ->and(CompanyMembership::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $invitedUser->getKey())
            ->count())->toBe(1);

    expect(fn () => $competitor->table('company_user')->insert([
        'company_id' => $company->getKey(),
        'user_id' => $invitedUser->getKey(),
        'role' => CompanyRole::Viewer->value,
        'is_owner' => false,
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $rollbackUser = User::factory()->create(['email' => 'rollback@example.com']);
    $rollbackToken = 'rollback-token-'.bin2hex(random_bytes(32));
    $rollbackInvitation = CompanyInvitation::factory()->pending()->create([
        'company_id' => $company,
        'email' => $rollbackUser->email,
        'token_hash' => hash('sha256', $rollbackToken),
    ]);

    expect(fn () => $acceptAction->execute(
        $rollbackInvitation,
        $rollbackUser,
        'incorrect-token',
    ))->toThrow(InvitationCannotBeAccepted::class)
        ->and(CompanyMembership::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $rollbackUser->getKey())
            ->exists())->toBeFalse()
        ->and($rollbackInvitation->fresh()->accepted_at)->toBeNull()
        ->and($membership->role)->toBe(CompanyRole::Editor);

    DB::disconnect('mysql_invitation_competitor');
});
