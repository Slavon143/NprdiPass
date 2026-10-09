<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Companies\ChangeCompanyMemberRole;
use App\Authorization\CompanyAuthorizer;
use App\Authorization\CompanyInvitationAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a mutation rejects committed authorization revocation behind an older transaction snapshot', function (string $revocation) {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->editor()->create(['user_id' => $actor, 'company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    config()->set('database.connections.mysql_auth_revoker', config('database.connections.mysql'));
    DB::purge('mysql_auth_revoker');
    $revoker = DB::connection('mysql_auth_revoker');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();

    try {
        Company::query()->findOrFail($company->id);
        User::query()->findOrFail($actor->id);
        expect(app(CompanyAuthorizer::class)->allows($actor, $company, CompanyPermission::CatalogCreate))->toBeTrue();
        match ($revocation) {
            'role' => $revoker->table('company_user')->where('id', $membership->id)->update(['role' => 'viewer', 'is_owner' => false]),
            'membership' => $revoker->table('company_user')->where('id', $membership->id)->delete(),
            'company' => $revoker->table('companies')->where('id', $company->id)->update(['status' => 'suspended']),
            'user' => $revoker->table('users')->where('id', $actor->id)->update(['status' => 'suspended']),
            'deleted user' => $revoker->table('users')->where('id', $actor->id)->update(['deleted_at' => now()]),
        };

        expect(fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Denied', 'slug' => 'denied']))
            ->toThrow(AuthorizationException::class);
        expect(Product::query()->forCompany($company)->count())->toBe(0)
            ->and(ProductVariant::query()->forCompany($company)->count())->toBe(0)
            ->and(AuditLog::query()->where('company_id', $company->id)->count())->toBe(0);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_auth_revoker');
    }
})->with(['role', 'membership', 'company', 'user', 'deleted user']);

test('a downgraded owner cannot promote another owner using a stale role snapshot', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    $target = CompanyMembership::factory()->viewer()->create(['company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    config()->set('database.connections.mysql_auth_revoker', config('database.connections.mysql'));
    DB::purge('mysql_auth_revoker');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();

    try {
        expect(app(CompanyAuthorizer::class)->allows($actor, $company, CompanyPermission::MembersUpdateRole))->toBeTrue();
        DB::connection('mysql_auth_revoker')->table('company_user')->where('id', $membership->id)
            ->update(['role' => 'admin', 'is_owner' => false]);
        expect(fn () => app(ChangeCompanyMemberRole::class)->execute($actor, $target, CompanyRole::Owner))
            ->toThrow(AuthorizationException::class);
        expect($target->fresh()->role)->toBe(CompanyRole::Viewer)
            ->and(AuditLog::query()->where('company_id', $company->id)->count())->toBe(0);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_auth_revoker');
    }
});

test('authorization keeps company user and membership revocation serialized until the outer transaction ends', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create(['user_id' => $actor, 'company_id' => $company]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    config()->set('database.connections.mysql_auth_revoker', config('database.connections.mysql'));
    DB::purge('mysql_auth_revoker');
    $revoker = DB::connection('mysql_auth_revoker');
    $revoker->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $primary = DB::connection();
    $primary->beginTransaction();
    try {
        app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Authorized', 'slug' => 'authorized']);
        foreach (['company_user' => $membership->id, 'users' => $actor->id, 'companies' => $company->id] as $table => $id) {
            try {
                $revoker->table($table)->where('id', $id)->update($table === 'company_user' ? ['role' => 'viewer'] : ['status' => 'suspended']);
                $this->fail('Revocation escaped the outer transaction: '.$table);
            } catch (QueryException $exception) {
                expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
            }
        }
        $primary->commit();
        $revoker->table('company_user')->where('id', $membership->id)->update(['role' => 'viewer', 'is_owner' => false]);
        expect(fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Denied', 'slug' => 'denied']))
            ->toThrow(AuthorizationException::class);
        expect(Product::query()->forCompany($company)->pluck('slug')->all())->toBe(['authorized']);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_auth_revoker');
    }
});

test('invitation management checks the current invited role behind an older snapshot', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->admin()->create(['user_id' => $actor, 'company_id' => $company]);
    $invitation = CompanyInvitation::factory()->create(['company_id' => $company, 'role' => CompanyRole::Viewer]);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    config()->set('database.connections.mysql_auth_revoker', config('database.connections.mysql'));
    DB::purge('mysql_auth_revoker');
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        expect(app(CompanyInvitationAuthorizer::class)->allowsManage($actor, $invitation))->toBeTrue();
        DB::connection('mysql_auth_revoker')->table('company_invitations')->where('id', $invitation->id)->update(['role' => 'owner']);
        expect(fn () => app(CompanyInvitationAuthorizer::class)->authorizeManage($actor, $invitation))
            ->toThrow(AuthorizationException::class);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_auth_revoker');
    }
});
