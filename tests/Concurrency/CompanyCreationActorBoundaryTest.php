<?php

use App\Actions\Companies\CreateCompanyForOwner;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('company creation rejects a revoked account behind an older transaction snapshot', function (string $revocation) {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    config()->set('database.connections.mysql_company_actor_revoker', config('database.connections.mysql'));
    DB::purge('mysql_company_actor_revoker');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        User::query()->findOrFail($actor->id);
        DB::connection('mysql_company_actor_revoker')->table('users')->where('id', $actor->id)
            ->update($revocation === 'suspended' ? ['status' => 'suspended'] : ['deleted_at' => now()]);
        $denial = null;
        try {
            app(CreateCompanyForOwner::class)->execute($actor, 'Denied', null, 'SE');
        } catch (QueryException $exception) {
            $this->fail('Unexpected database failure: '.($exception->errorInfo[1] ?? 0).' '.($exception->errorInfo[2] ?? ''));
        } catch (AuthorizationException $exception) {
            $denial = $exception;
        }
        expect($denial)->toBeInstanceOf(AuthorizationException::class);
        expect(Company::query()->count())->toBe(0)
            ->and(CompanyMembership::query()->count())->toBe(0)
            ->and(CompanySubscription::query()->count())->toBe(0);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_company_actor_revoker');
    }
})->with(['suspended', 'erased']);
