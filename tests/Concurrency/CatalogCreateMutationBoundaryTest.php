<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Authorization\ApiTokenMutationAuthorizer;
use App\Domain\Api\Exceptions\ApiTokenInvalid;
use App\Enums\ApiTokenAbility;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Exceptions\Catalog\ProductOperationException;
use App\Models\AuditLog;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\CatalogCapacityReconciler;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function createMutationFixture(): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    test()->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->editor()->create(['user_id' => $actor, 'company_id' => $company]);
    CompanySubscription::query()->create(['company_id' => $company->id, 'plan_key' => 'growth',
        'status' => BillingSubscriptionStatus::Active, 'entitlements' => ['catalog.products.max' => 3]]);
    app(CatalogCapacityReconciler::class)->reconcile($company->id, true);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company, $membership];
}

test('nested creates perform one current permission check per row and keep API credential checks', function (bool $api) {
    [$actor, $company] = createMutationFixture();
    $issued = $api ? issueCompanyApiToken($actor, $company, [ApiTokenAbility::CatalogWrite->value]) : null;
    if ($issued !== null) {
        $actor->withAccessToken($issued->accessToken);
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql; // No bindings or token hashes in the evidence.
    });
    $create = function () use ($actor, $company): void {
        DB::transaction(function () use ($actor, $company): void {
            $action = app(CreateProductAction::class);
            $action->execute($actor, $company, ['name' => 'First', 'slug' => 'first']);
            $action->execute($actor, $company, ['name' => 'Second', 'slug' => 'second']);
        });
    };
    if ($issued !== null) {
        app(ApiTokenMutationAuthorizer::class)->withToken($issued->accessToken, ApiTokenAbility::CatalogWrite->value, $create);
    } else {
        $create();
    }
    $sharedReads = fn (string $table): int => count(array_filter($queries,
        fn (string $sql): bool => str_contains($sql, 'from `'.$table.'`') && str_contains($sql, 'lock in share mode')));
    expect($sharedReads('users'))->toBe($api ? 4 : 2)
        ->and($sharedReads('company_user'))->toBe($api ? 4 : 2)
        ->and($sharedReads('personal_access_tokens'))->toBe($api ? 2 : 0);
    expect($company->products()->count())->toBe(2)->and($company->productVariants()->count())->toBe(2)
        ->and($company->catalogCapacity()->value('products_count'))->toBe(2);
})->with([false, true]);

test('nested create revalidates access at the savepoint boundary before validating payload', function (string $revocation) {
    [$actor, $company, $membership] = createMutationFixture();
    $issued = $revocation === 'token' ? issueCompanyApiToken($actor, $company, [ApiTokenAbility::CatalogWrite->value]) : null;
    if ($issued !== null) {
        $actor->withAccessToken($issued->accessToken);
    }
    $changed = false;
    $primary = DB::connection();
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use ($primary, $revocation, $actor, $company, $membership, $issued, &$changed): void {
        if ($changed || $event->connection !== $primary || $primary->transactionLevel() !== 2) {
            return;
        }
        $changed = true;
        match ($revocation) {
            'role' => DB::table('company_user')->where('id', $membership->id)->update(['role' => 'viewer']),
            'membership' => DB::table('company_user')->where('id', $membership->id)->delete(),
            'user' => DB::table('users')->where('id', $actor->id)->update(['status' => 'suspended']),
            'company' => DB::table('companies')->where('id', $company->id)->update(['status' => 'suspended']),
            'token' => DB::table('personal_access_tokens')->where('id', $issued->accessToken->id)->delete(),
        };
    });
    $create = fn () => DB::transaction(fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => '']));
    try {
        $execute = $issued === null ? $create : fn () => app(ApiTokenMutationAuthorizer::class)
            ->withToken($issued->accessToken, ApiTokenAbility::CatalogWrite->value, $create);
        expect($execute)->toThrow($issued === null ? AuthorizationException::class : ApiTokenInvalid::class);
        expect($changed)->toBeTrue()->and($company->products()->count())->toBe(0)
            ->and($company->productVariants()->count())->toBe(0)->and($company->catalogCapacity()->value('products_count'))->toBe(0)
            ->and(AuditLog::query()->where('company_id', $company->id)->where('event', 'catalog.product.created')->count())->toBe(0);
    } finally {
        Event::forget(TransactionBeginning::class);
    }
})->with(['role', 'membership', 'user', 'company', 'token']);

test('the same create action does not reuse permission after an own transaction role change', function () {
    [$actor, $company, $membership] = createMutationFixture();
    DB::transaction(function () use ($actor, $company, $membership): void {
        $action = app(CreateProductAction::class);
        $action->execute($actor, $company, ['name' => 'Allowed', 'slug' => 'allowed']);
        DB::table('company_user')->where('id', $membership->id)->update(['role' => 'viewer']);
        expect(fn () => $action->execute($actor, $company, ['name' => 'Denied', 'slug' => 'denied']))
            ->toThrow(AuthorizationException::class);
    });
    expect($company->products()->count())->toBe(1)->and($company->productVariants()->count())->toBe(1)
        ->and($company->catalogCapacity()->value('products_count'))->toBe(1);
});

test('standalone create preserves authorization and validation preflight before opening a transaction', function () {
    [$actor, $company, $membership] = createMutationFixture();
    $beginnings = 0;
    Event::listen(TransactionBeginning::class, function () use (&$beginnings): void {
        $beginnings++;
    });
    try {
        DB::table('company_user')->where('id', $membership->id)->update(['role' => 'viewer']);
        expect(fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => '']))->toThrow(AuthorizationException::class);
        DB::table('company_user')->where('id', $membership->id)->update(['role' => 'editor']);
        expect(fn () => app(CreateProductAction::class)->execute($actor, $company, ['name' => '']))->toThrow(ProductOperationException::class);
        expect($beginnings)->toBe(0)->and($company->catalogCapacity()->value('products_count'))->toBe(0);
    } finally {
        Event::forget(TransactionBeginning::class);
    }
});
