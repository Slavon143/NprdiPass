<?php

use App\Domain\Companies\Exceptions\LastCompanyOwnerCannotBeRemoved;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Privacy\AccountErasureService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('erasure checks current ownership after an older snapshot missed an ownership transfer', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->editor()->create(['user_id' => $actor, 'company_id' => $company]);
    $oldOwner = CompanyMembership::factory()->owner()->create(['company_id' => $company]);
    $revoker = erasureBoundaryCompetitor();
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        CompanyMembership::query()->where('user_id', $actor->id)->get();
        $revoker->transaction(function () use ($revoker, $company, $membership, $oldOwner): void {
            $revoker->table('companies')->where('id', $company->id)->lockForUpdate()->first();
            $revoker->table('company_user')->where('id', $oldOwner->id)->update(['role' => 'viewer', 'is_owner' => false]);
            $revoker->table('company_user')->where('id', $membership->id)->update(['role' => 'owner', 'is_owner' => true]);
        });
        expect(fn () => app(AccountErasureService::class)->erase($actor))->toThrow(LastCompanyOwnerCannotBeRemoved::class);
        expect($revoker->table('company_user')->where('company_id', $company->id)->where('role', 'owner')->count())->toBe(1)
            ->and($actor->fresh()->trashed())->toBeFalse()
            ->and(AuditLog::query()->count())->toBe(0);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_erasure_competitor');
    }
});

test('erasure rejects tenants added behind the discovery snapshot before deleting personal data', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $addedCompany = Company::factory()->create();
    CompanyMembership::factory()->viewer()->create(['user_id' => $actor, 'company_id' => $company]);
    $revoker = erasureBoundaryCompetitor();
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        CompanyMembership::query()->where('user_id', $actor->id)->get();
        $revoker->table('company_user')->insert([
            'company_id' => $addedCompany->id, 'user_id' => $actor->id,
            'role' => 'owner', 'is_owner' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        expect(fn () => app(AccountErasureService::class)->erase($actor))->toThrow(ValidationException::class);
        expect($revoker->table('company_user')->where('user_id', $actor->id)->count())->toBe(2)
            ->and($actor->fresh()->trashed())->toBeFalse()
            ->and(AuditLog::query()->count())->toBe(0);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_erasure_competitor');
    }
});

test('erasure waits for the tenant mutation mutex before removing membership or anonymizing the user', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->viewer()->create(['user_id' => $actor, 'company_id' => $company]);
    $holder = erasureBoundaryCompetitor();
    $holder->beginTransaction();
    $holder->table('companies')->where('id', $company->id)->lockForUpdate()->first();
    $primary = DB::connection();
    $timeout = (int) $primary->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS timeout')->timeout;
    $primary->statement('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        try {
            app(AccountErasureService::class)->erase($actor);
            $this->fail('Erasure bypassed the tenant mutation mutex.');
        } catch (QueryException $exception) {
            expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
        }
        expect($actor->fresh()->trashed())->toBeFalse()
            ->and(CompanyMembership::query()->whereKey($membership->id)->exists())->toBeTrue()
            ->and(AuditLog::query()->count())->toBe(0);
    } finally {
        if ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }
        $primary->statement('SET SESSION innodb_lock_wait_timeout = '.$timeout);
        DB::disconnect('mysql_erasure_competitor');
    }
});

function erasureBoundaryCompetitor(): Connection
{
    config()->set('database.connections.mysql_erasure_competitor', config('database.connections.mysql'));
    DB::purge('mysql_erasure_competitor');

    return DB::connection('mysql_erasure_competitor');
}
