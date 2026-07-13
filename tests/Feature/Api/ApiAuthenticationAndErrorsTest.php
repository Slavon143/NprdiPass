<?php

use App\Enums\ApiTokenAbility;
use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Exceptions\CurrentCompanyNotSet;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

test('missing malformed revoked and expired bearer tokens return stable 401 errors', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer malformed-token'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_invalid');

    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);
    $revoked = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);
    $revokedRaw = $revoked->plainTextToken;
    $revoked->accessToken->delete();
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$revokedRaw])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_invalid');

    $expired = issueCompanyApiToken(
        $user,
        $company,
        [ApiTokenAbility::CompanyRead->value],
        now()->subMinute(),
    );
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$expired->plainTextToken])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_expired');
});

test('suspended users and missing memberships are denied immediately', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->owner()->create([
        'company_id' => $company,
        'user_id' => $user,
    ]);
    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);
    $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

    $user->update(['status' => UserStatus::Suspended]);
    $this->getJson('/api/v1/company', $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_invalid');

    $user->update(['status' => UserStatus::Active]);
    $membership->delete();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/company', $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('suspended and archived token companies use documented statuses', function (CompanyStatus $status, int $httpStatus) {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);
    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);
    $company->update(['status' => $status]);

    $this->getJson('/api/v1/company', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ])->assertStatus($httpStatus)
        ->assertJsonPath('error.code', 'company_inactive');
})->with([
    'suspended' => [CompanyStatus::Suspended, 423],
    'archived' => [CompanyStatus::Archived, 403],
]);

test('soft deleted company and null company tokens are invalid', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);
    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);
    $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

    $company->delete();
    $this->getJson('/api/v1/company', $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_invalid');

    $company->restore();
    $token->accessToken->forceFill(['company_id' => null])->save();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/company', $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'token_invalid');
});

test('all common API failures use JSON envelopes without internal messages', function () {
    Route::middleware('api')->get('/api/v1/test-conflict', fn () => throw new CurrentCompanyNotSet);
    Route::middleware('api')->get('/api/v1/test-error', fn () => throw new RuntimeException('sensitive internal detail'));

    $this->getJson('/api/v1/missing')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'resource_not_found');
    $this->getJson('/api/v1/test-conflict')
        ->assertConflict()
        ->assertJsonPath('error.code', 'current_company_missing');
    $internal = $this->getJson('/api/v1/test-error')
        ->assertServerError()
        ->assertJsonPath('error.code', 'internal_error')
        ->assertJsonPath('error.message', 'An unexpected error occurred.');

    expect($internal->getContent())->not->toContain('sensitive internal detail')
        ->and($internal->getContent())->not->toContain('RuntimeException');
});

test('API public rate limiting returns the error envelope', function () {
    RateLimiter::for('api-public', fn ($request): Limit => Limit::perMinute(2)->by($request->ip()));

    $this->getJson('/api/v1/health')->assertOk();
    $this->getJson('/api/v1/health')->assertOk();
    $this->getJson('/api/v1/health')
        ->assertTooManyRequests()
        ->assertJsonPath('data', null)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonStructure(['meta' => ['request_id']]);
});

test('authenticated rate limiting is isolated per token without raw token keys', function () {
    RateLimiter::for('api-authenticated', function ($request): Limit {
        $token = $request->user()?->currentAccessToken();

        return Limit::perMinute(1)->by('token:'.$token?->getKey());
    });
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);
    $tokenA = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);
    $tokenB = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);

    $this->getJson('/api/v1/company', ['Authorization' => 'Bearer '.$tokenA->plainTextToken])->assertOk();
    $this->getJson('/api/v1/company', ['Authorization' => 'Bearer '.$tokenA->plainTextToken])
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/company', ['Authorization' => 'Bearer '.$tokenB->plainTextToken])->assertOk();

    $cacheKeys = implode('|', array_keys(app('cache')->getStore()->many([])));
    expect($cacheKeys)->not->toContain($tokenA->plainTextToken)
        ->and($cacheKeys)->not->toContain($tokenB->plainTextToken);
});

test('authenticated limiter runs before the endpoint ability check', function () {
    RateLimiter::for('api-authenticated', function ($request): Limit {
        $token = $request->user()?->currentAccessToken();

        return Limit::perMinute(1)->by('token:'.$token?->getKey());
    });
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);
    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);
    $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

    $this->getJson('/api/v1/company/members', $headers)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'token_ability_missing');
    $this->getJson('/api/v1/company/members', $headers)
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'rate_limited');
});

test('CORS allows configured origins without credential wildcard combination', function () {
    config()->set('cors.allowed_origins', ['http://localhost:5173']);
    config()->set('cors.supports_credentials', false);

    $this->options('/api/v1/health', [], [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ])->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');

    expect(config('cors.allowed_origins'))->not->toContain('*')
        ->and(config('cors.supports_credentials'))->toBeFalse();
});
