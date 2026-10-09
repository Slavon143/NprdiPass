<?php

require_once __DIR__.'/../../Fixtures/FortnoxOAuthTokens.php';

use App\Exceptions\Fortnox\FortnoxSubscriptionBlocked;
use App\Services\Fortnox\FakeFortnoxProvider;
use App\Services\Fortnox\FortnoxSubscriptionGuard;
use App\Services\Fortnox\HttpFortnoxProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['fortnox.nonproduction_client_id' => 'fixture-client']);
});

function guardedFortnoxHttpProvider(): HttpFortnoxProvider
{
    return new HttpFortnoxProvider(['client_id' => 'fixture-client', 'client_secret' => 'fixture-secret']);
}

test('real Fortnox authorization is disabled by default outside production', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);
    config(['fortnox.nonproduction_oauth_enabled' => false]);
    Http::fake();

    expect(fn () => guardedFortnoxHttpProvider()->authorizationUrl('fixture-state', 'https://nordipass.test/callback'))
        ->toThrow(FortnoxSubscriptionBlocked::class, 'disabled');
    expect(fn () => guardedFortnoxHttpProvider()->exchangeAuthorizationCode('fixture-code', 'https://nordipass.test/callback'))
        ->toThrow(FortnoxSubscriptionBlocked::class, 'disabled');
    Http::assertNothingSent();
})->with(['local', 'development', 'staging', 'testing', 'review']);

test('production Fortnox subscription remains blocked even if configured as the allowed test account', function () {
    app()->detectEnvironment(fn (): string => 'staging');
    config(['fortnox.nonproduction_oauth_enabled' => true, 'fortnox.nonproduction_tenant_id' => '1853390']);

    expect(fn () => guardedFortnoxHttpProvider()->authorizationUrl('fixture-state', 'https://nordipass.test/callback'))
        ->toThrow(FortnoxSubscriptionBlocked::class, '1853390');
});

test('nonproduction OAuth and refresh reject an unverified or mismatched client before network I O', function (string $expectedClient) {
    app()->detectEnvironment(fn (): string => 'staging');
    config(['fortnox.nonproduction_oauth_enabled' => true, 'fortnox.nonproduction_client_id' => $expectedClient]);
    Http::fake();

    expect(fn () => guardedFortnoxHttpProvider()->authorizationUrl('fixture-state', 'https://nordipass.test/callback'))
        ->toThrow(FortnoxSubscriptionBlocked::class, 'verified Fortnox test client');
    expect(fn () => guardedFortnoxHttpProvider()->refreshAccessToken('fixture-refresh', '1854146'))
        ->toThrow(FortnoxSubscriptionBlocked::class, 'verified Fortnox test client');
    Http::assertNothingSent();
})->with(['missing' => '', 'wrong client' => 'different-client']);

test('fake Fortnox never starts real OAuth even when real credentials are configured', function () {
    config(['fortnox.client_id' => 'configured-client', 'fortnox.client_secret' => 'configured-secret']);
    $url = app(FakeFortnoxProvider::class)->authorizationUrl('fixture-state', 'https://nordipass.test/callback');

    expect($url)->toStartWith('https://nordipass.test/callback?')
        ->not->toContain('apps.fortnox.se')->not->toContain('client_id=');
});

test('token identity parser rejects malformed or nonnumeric identities', function (string $token) {
    expect(app(FortnoxSubscriptionGuard::class)->tenantIdFromServerToken($token))->toBeNull();
})->with([
    'opaque' => 'opaque-fixture',
    'broken base64' => 'header.!broken.signature',
    'invalid json' => 'header.eA.signature',
    'boolean' => fn () => fortnoxTenantJwt(true),
    'array' => fn () => fortnoxTenantJwt(['1854146']),
    'float' => fn () => fortnoxTenantJwt(1854146.5),
    'leading zero' => fn () => fortnoxTenantJwt('01854146'),
    'oversized' => str_repeat('a', 16385),
]);

test('wrong or unidentified token is blocked before any Fortnox article request', function (string $token) {
    app()->detectEnvironment(fn (): string => 'staging');
    Http::fake();

    expect(fn () => guardedFortnoxHttpProvider()->listArticles($token))
        ->toThrow(FortnoxSubscriptionBlocked::class);
    Http::assertNothingSent();
})->with([
    'production' => fn () => fortnoxTenantJwt('1853390'),
    'other subscription' => fn () => fortnoxTenantJwt('9999999'),
    'unknown' => 'opaque-fixture',
]);

test('trusted token response records the permitted test subscription without adding undocumented account selector parameters', function () {
    app()->detectEnvironment(fn (): string => 'staging');
    config(['fortnox.nonproduction_oauth_enabled' => true]);
    $accessToken = fortnoxTenantJwt(1854146);
    Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response([
        'access_token' => $accessToken, 'refresh_token' => 'fixture-refresh', 'scope' => 'article', 'expires_in' => 3600,
    ])]);
    $provider = guardedFortnoxHttpProvider();
    parse_str((string) parse_url($provider->authorizationUrl('fixture-state', 'https://nordipass.test/callback'), PHP_URL_QUERY), $query);
    expect($query)->not->toHaveKey('tenantId')->not->toHaveKey('subscription_id');
    expect($provider->exchangeAuthorizationCode('fixture-code', 'https://nordipass.test/callback')->tenantId)->toBe('1854146');
    Http::assertSentCount(1);
});

test('untrusted token response fields cannot override a production tenant inside the server token', function () {
    app()->detectEnvironment(fn (): string => 'staging');
    config(['fortnox.nonproduction_oauth_enabled' => true]);
    Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response([
        'access_token' => fortnoxTenantJwt('1853390'), 'tenantId' => '1854146', 'scope' => 'article',
    ])]);

    expect(fn () => guardedFortnoxHttpProvider()->exchangeAuthorizationCode('fixture-code', 'https://nordipass.test/callback'))
        ->toThrow(FortnoxSubscriptionBlocked::class, '1853390');
});

test('refresh requires verified test context before sending an opaque refresh credential', function (?string $tenantId) {
    app()->detectEnvironment(fn (): string => 'staging');
    Http::fake();

    expect(fn () => guardedFortnoxHttpProvider()->refreshAccessToken('fixture-refresh', $tenantId))
        ->toThrow(FortnoxSubscriptionBlocked::class);
    Http::assertNothingSent();
})->with(['missing' => null, 'production' => '1853390', 'other' => '9999999']);

test('permitted test token can refresh and read articles without new OAuth authorization', function () {
    app()->detectEnvironment(fn (): string => 'staging');
    config(['fortnox.nonproduction_oauth_enabled' => false]);
    Http::fake([
        'apps.fortnox.se/oauth-v1/token' => Http::response(['access_token' => fortnoxTenantJwt('1854146'), 'scope' => 'article']),
        'api.fortnox.se/3/articles*' => Http::response(['Articles' => [], 'MetaInformation' => ['@CurrentPage' => 1, '@TotalPages' => 1]]),
    ]);
    $provider = guardedFortnoxHttpProvider();
    $token = $provider->refreshAccessToken('fixture-refresh', '1854146');
    expect($token->tenantId)->toBe('1854146')->and($provider->listArticles($token->accessToken)->articles)->toBe([]);
    Http::assertSentCount(2);
});

test('production environment transport remains outside the nonproduction restriction', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['fortnox.nonproduction_oauth_enabled' => false]);
    Http::fake(['apps.fortnox.se/oauth-v1/token' => Http::response(['access_token' => fortnoxTenantJwt('1853390')])]);

    expect(guardedFortnoxHttpProvider()->authorizationUrl('fixture-state', 'https://nordipass.test/callback'))->toContain('response_type=code')
        ->and(guardedFortnoxHttpProvider()->exchangeAuthorizationCode('fixture-code', 'https://nordipass.test/callback')->tenantId)->toBe('1853390');
});
