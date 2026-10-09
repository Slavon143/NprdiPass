<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Authorization\ApiTokenMutationAuthorizer;
use App\Authorization\CompanyAuthorizer;
use App\Domain\Api\Exceptions\ApiTokenAbilityMissing;
use App\Domain\Api\Exceptions\ApiTokenExpired;
use App\Domain\Api\Exceptions\ApiTokenInvalid;
use App\Enums\ApiTokenAbility;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\CompanyPermission;
use App\Models\AuditLog;
use App\Models\Billing\CompanySubscription;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\CatalogCapacityReconciler;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function catalogLockFixture(): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    test()->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->editor()->create(['company_id' => $company, 'user_id' => $actor]);
    CompanySubscription::query()->create(['company_id' => $company->id, 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active, 'entitlements' => ['catalog.products.max' => 2]]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
    $product = app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Existing', 'slug' => 'existing']);
    config(['database.connections.mysql_catalog_competitor' => config('database.connections.mysql')]);
    DB::purge('mysql_catalog_competitor');
    $competitor = DB::connection('mysql_catalog_competitor');
    $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');

    return [$actor, $company, $membership, $product, $competitor];
}

test('independent metadata writes finish while catalogue admission remains uncommitted', function (bool $api) {
    [$actor, $company, , $product] = catalogLockFixture();
    $issued = $api ? issueCompanyApiToken($actor, $company, [ApiTokenAbility::CatalogWrite->value]) : null;
    if ($issued !== null) {
        $actor->withAccessToken($issued->accessToken);
    }
    $primary = DB::connection();
    $run = function () use ($primary, $actor, $company, $product, $issued): void {
        $primary->beginTransaction();
        try {
            app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Uncommitted', 'slug' => 'uncommitted']);
            config(['database.default' => 'mysql_catalog_competitor']);
            try {
                $editor = User::query()->findOrFail($actor->id);
                if ($issued !== null) {
                    $editor->withAccessToken($issued->accessToken);
                }
                $tenant = Company::query()->findOrFail($company->id);
                app(CurrentCompany::class)->set($tenant);
                $target = Product::query()->forCompany($tenant)->findOrFail($product->id);
                expect(fn () => app(UpdateProductAction::class)->execute($editor, $tenant, $target, ['brand' => 'Concurrent']))
                    ->not->toThrow(QueryException::class);
                expect($target->fresh()->brand)->toBe('Concurrent');
            } finally {
                config(['database.default' => 'mysql']);
                app(CurrentCompany::class)->set($company);
            }
        } finally {
            $primary->rollBack();
        }
    };
    try {
        if ($issued === null) {
            $run();
        } else {
            app(ApiTokenMutationAuthorizer::class)->withToken($issued->accessToken, ApiTokenAbility::CatalogWrite->value, $run);
        }
        expect($product->fresh()->brand)->toBe('Concurrent')->and($company->products()->count())->toBe(1)
            ->and(AuditLog::query()->where('company_id', $company->id)->where('event', 'catalog.product.updated')->count())->toBe(1);
        expect(app(CatalogCapacityReconciler::class)->reconcile($company->id))->toMatchArray(['previous_count' => 1, 'actual_count' => 1, 'repaired' => false]);
    } finally {
        DB::disconnect('mysql_catalog_competitor');
    }
})->with([false, true]);

test('shared catalogue admission still holds company user membership and token revocation boundaries', function () {
    [$actor, $company, $membership, , $competitor] = catalogLockFixture();
    $issued = issueCompanyApiToken($actor, $company, [ApiTokenAbility::CatalogWrite->value]);
    $actor->withAccessToken($issued->accessToken);
    $primary = DB::connection();
    $primary->beginTransaction();
    try {
        app(ApiTokenMutationAuthorizer::class)->withToken($issued->accessToken, ApiTokenAbility::CatalogWrite->value,
            fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Pending']));
        foreach ([
            fn () => $competitor->table('companies')->where('id', $company->id)->update(['status' => 'suspended']),
            fn () => $competitor->table('users')->where('id', $actor->id)->update(['status' => 'suspended']),
            fn () => $competitor->table('company_user')->where('id', $membership->id)->update(['role' => 'viewer']),
            fn () => $competitor->table('personal_access_tokens')->where('id', $issued->accessToken->id)->delete(),
        ] as $revoke) {
            expect($revoke)->toThrow(QueryException::class, 'Lock wait timeout');
        }
        $primary->rollBack();
        expect($competitor->table('personal_access_tokens')->where('id', $issued->accessToken->id)->delete())->toBe(1);
        expect(fn () => app(ApiTokenMutationAuthorizer::class)->withToken($issued->accessToken, ApiTokenAbility::CatalogWrite->value,
            fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Revoked'])))
            ->toThrow(ApiTokenInvalid::class);
        expect($company->products()->count())->toBe(1);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_catalog_competitor');
    }
});

test('shared catalogue authorization cannot be requested for administrative permissions', function () {
    [$actor, $company] = catalogLockFixture();
    expect(fn () => app(CompanyAuthorizer::class)->authorizeCatalogWrite($actor, $company, CompanyPermission::MembersRemove))
        ->toThrow(LogicException::class);
});
test('catalogue worker admission permits an independent API kernel update', function () {
    [$actor, $company, , $product] = catalogLockFixture();
    $issued = issueCompanyApiToken($actor, $company, [ApiTokenAbility::CatalogWrite->value]);
    $primary = DB::connection();
    $primary->beginTransaction();
    try {
        app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Worker pending']);
        config(['database.default' => 'mysql_catalog_competitor']);
        try {
            app(CurrentCompany::class)->clear();
            Auth::forgetGuards();
            $response = $this->withHeader('Authorization', 'Bearer '.$issued->plainTextToken)
                ->patchJson('/api/v1/catalog/products/'.$product->uuid, ['brand' => 'Through API']);
            $response->assertOk()->assertJsonPath('data.brand', 'Through API');
            expect(DB::table('personal_access_tokens')->where('id', $issued->accessToken->id)->value('last_used_at'))->not->toBeNull();
        } finally {
            config(['database.default' => 'mysql']);
            app(CurrentCompany::class)->set($company);
            Auth::forgetGuards();
        }
    } finally {
        $primary->rollBack();
        DB::disconnect('mysql_catalog_competitor');
    }
    expect($product->fresh()->brand)->toBe('Through API')->and($company->products()->count())->toBe(1)
        ->and(AuditLog::query()->where('company_id', $company->id)->where('event', 'catalog.product.updated')->count())->toBe(1);
    expect(app(CatalogCapacityReconciler::class)->reconcile($company->id))->toMatchArray(['previous_count' => 1, 'actual_count' => 1, 'repaired' => false]);
});
test('shared company locks retain product exclusion and rollback its metadata and audit', function () {
    [$actor, $company, , $product] = catalogLockFixture();
    $primary = DB::connection();
    $primary->beginTransaction();
    try {
        app(UpdateProductAction::class)->execute($actor, $company, $product, ['brand' => 'Pending']);
        config(['database.default' => 'mysql_catalog_competitor']);
        try {
            $tenant = Company::findOrFail($company->id);
            app(CurrentCompany::class)->set($tenant);
            $editor = User::findOrFail($actor->id);
            $target = Product::query()->forCompany($tenant)->findOrFail($product->id);
            expect(fn () => app(UpdateProductAction::class)->execute($editor, $tenant, $target, ['brand' => 'Racing']))
                ->toThrow(QueryException::class, 'Lock wait timeout');
        } finally {
            config(['database.default' => 'mysql']);
            app(CurrentCompany::class)->set($company);
        }
    } finally {
        $primary->rollBack();
        DB::disconnect('mysql_catalog_competitor');
    }
    expect($product->fresh()->brand)->toBeNull()
        ->and(AuditLog::query()->where('company_id', $company->id)->where('event', 'catalog.product.updated')->count())->toBe(0);
    expect(app(CatalogCapacityReconciler::class)->reconcile($company->id))->toMatchArray(['previous_count' => 1, 'actual_count' => 1, 'repaired' => false]);
});

test('shared catalogue writes reject committed credential changes behind an older snapshot', function (string $change, string $exception) {
    [$actor, $company, , , $competitor] = catalogLockFixture();
    $issued = issueCompanyApiToken($actor, $company, [ApiTokenAbility::CatalogWrite->value]);
    $actor->withAccessToken($issued->accessToken);
    $primary = DB::connection();
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        DB::table('personal_access_tokens')->where('id', $issued->accessToken->id)->first(['id']);
        $tokens = $competitor->table('personal_access_tokens')->where('id', $issued->accessToken->id);
        match ($change) {
            'revoke' => $tokens->delete(),
            'expire' => $tokens->update(['expires_at' => now()->subMinute()]),
            'ability' => $tokens->update(['abilities' => '[]']),
        };
        expect(fn () => app(ApiTokenMutationAuthorizer::class)->withToken($issued->accessToken, ApiTokenAbility::CatalogWrite->value,
            fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => 'Stale credential'])))
            ->toThrow($exception);
    } finally {
        $primary->rollBack();
        DB::disconnect('mysql_catalog_competitor');
    }
    expect($company->products()->count())->toBe(1)
        ->and(AuditLog::query()->where('company_id', $company->id)->where('event', 'catalog.product.created')->count())->toBe(1);
    expect(app(CatalogCapacityReconciler::class)->reconcile($company->id))->toMatchArray(['previous_count' => 1, 'actual_count' => 1, 'repaired' => false]);
})->with([
    ['revoke', ApiTokenInvalid::class],
    ['expire', ApiTokenExpired::class],
    ['ability', ApiTokenAbilityMissing::class],
]);
