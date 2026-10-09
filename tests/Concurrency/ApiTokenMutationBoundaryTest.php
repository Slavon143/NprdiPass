<?php

use App\Domain\Api\Exceptions\ApiCompanyInactive;
use App\Domain\Api\Exceptions\ApiTokenAbilityMissing;
use App\Domain\Api\Exceptions\ApiTokenExpired;
use App\Domain\Api\Exceptions\ApiTokenInvalid;
use App\Enums\ApiTokenAbility;
use App\Http\Controllers\Api\V1\Webhooks\WebhookEndpointController;
use App\Http\Middleware\EnsureApiCompanyIsActive;
use App\Http\Middleware\EnsureApiCompanyMembership;
use App\Http\Middleware\EnsureApiTokenAbility;
use App\Http\Middleware\EnsureApiTokenIsValid;
use App\Http\Middleware\ResolveApiCompany;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Models\Webhooks\WebhookEndpoint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;

function apiCredentialConcurrencyFixture(): array
{
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    test()->artisan('migrate:fresh')->assertSuccessful();
    Http::preventStrayRequests();
    Queue::fake();
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create(['user_id' => $user, 'company_id' => $company]);
    $issued = issueCompanyApiToken($user, $company, [ApiTokenAbility::WebhooksManage->value]);
    $user->withAccessToken($issued->accessToken);
    config()->set('database.connections.mysql_api_competitor', config('database.connections.mysql'));
    DB::purge('mysql_api_competitor');

    return [$user, $company, $membership, $issued];
}

function apiCredentialStoreRequest(User $user, string $plainTextToken): Response
{
    $request = Request::create('/api/v1/webhooks/endpoints', 'POST', [
        'url' => 'https://example.test/concurrency', 'secret' => 'fixture-endpoint-secret', 'events' => ['passport.published'],
    ]);
    $request->headers->set('Authorization', 'Bearer '.$plainTextToken);
    $request->setUserResolver(fn () => $user);
    $middleware = [EnsureApiTokenIsValid::class, ResolveApiCompany::class, EnsureApiCompanyMembership::class, EnsureApiCompanyIsActive::class];
    $next = fn (Request $r): Response => app(EnsureApiTokenAbility::class)->handle($r,
        fn (Request $current): Response => app()->call([app(WebhookEndpointController::class), 'store'], ['request' => $current]),
        ApiTokenAbility::WebhooksManage->value);
    foreach (array_reverse($middleware) as $class) {
        $downstream = $next;
        $next = fn (Request $r): Response => app($class)->handle($r, $downstream);
    }

    return $next($request);
}

test('API mutation rejects a committed credential change behind an older snapshot', function (string $change, string $exceptionClass) {
    [$user, $company, $membership, $issued] = apiCredentialConcurrencyFixture();
    $primary = DB::connection();
    $competitor = DB::connection('mysql_api_competitor');
    $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $primary->beginTransaction();
    try {
        DB::table('personal_access_tokens')->where('id', $issued->accessToken->id)->first(['id']);
        $tokens = $competitor->table('personal_access_tokens')->where('id', $issued->accessToken->id);
        match ($change) {
            'revoke' => $tokens->delete(),
            'expire' => $tokens->update(['expires_at' => now()->subMinute()]),
            'ability' => $tokens->update(['abilities' => json_encode([ApiTokenAbility::CompanyRead->value])]),
            'membership' => $competitor->table('company_user')->where('id', $membership->id)->delete(),
            'company' => $competitor->table('companies')->where('id', $company->id)->update(['status' => 'suspended']),
        };
        expect(fn () => apiCredentialStoreRequest($user, $issued->plainTextToken))->toThrow($exceptionClass);
        expect(WebhookEndpoint::query()->count())->toBe(0);
    } finally {
        $primary->rollBack();
        DB::disconnect('mysql_api_competitor');
    }
})->with([
    ['revoke', ApiTokenInvalid::class], ['expire', ApiTokenExpired::class],
    ['ability', ApiTokenAbilityMissing::class], ['membership', AuthorizationException::class],
    ['company', ApiCompanyInactive::class],
]);

test('API mutation holds credential and tenant locks through the outer commit', function () {
    [$user, $company, $membership, $issued] = apiCredentialConcurrencyFixture();
    $primary = DB::connection();
    $competitor = DB::connection('mysql_api_competitor');
    $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $primary->beginTransaction();
    try {
        expect(apiCredentialStoreRequest($user, $issued->plainTextToken)->getStatusCode())->toBe(201);
        foreach ([
            fn () => $competitor->table('personal_access_tokens')->where('id', $issued->accessToken->id)->delete(),
            fn () => $competitor->table('personal_access_tokens')->where('id', $issued->accessToken->id)->update(['abilities' => '[]']),
            fn () => $competitor->table('company_user')->where('id', $membership->id)->delete(),
            fn () => $competitor->table('users')->where('id', $user->id)->update(['status' => 'suspended']),
            fn () => $competitor->table('companies')->where('id', $company->id)->update(['status' => 'suspended']),
        ] as $revoke) {
            try {
                $revoke();
                $this->fail('Revocation committed while the authorized write was uncommitted.');
            } catch (QueryException $exception) {
                expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
            }
        }
        $primary->commit();
        expect($competitor->table('personal_access_tokens')->where('id', $issued->accessToken->id)->delete())->toBe(1);
        expect(fn () => apiCredentialStoreRequest($user, $issued->plainTextToken))->toThrow(ApiTokenInvalid::class);
        expect(WebhookEndpoint::query()->count())->toBe(1);
    } finally {
        if ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        DB::disconnect('mysql_api_competitor');
    }
});
