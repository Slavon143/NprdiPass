<?php

use App\Data\Fortnox\FortnoxAccessToken;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Services\Fortnox\HttpFortnoxProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function fortnoxHttpProvider(array $overrides = []): HttpFortnoxProvider
{
    return new HttpFortnoxProvider(array_merge([
        'base_url' => 'https://api.fortnox.se',
        'authorize_url' => 'https://apps.fortnox.se/oauth-v1/auth',
        'token_url' => 'https://apps.fortnox.se/oauth-v1/token',
        'client_id' => 'client',
        'client_secret' => 'secret',
        'allowed_api_hosts' => ['api.fortnox.se', 'apps.fortnox.se'],
        'scopes' => ['article'],
        'sync_page_size' => 2,
    ], $overrides));
}

test('https and allowlisted hosts are accepted', function () {
    $provider = fortnoxHttpProvider();

    expect(fn () => $provider->assertSafeExternalUrl('https://api.fortnox.se'))
        ->not->toThrow(FortnoxProviderException::class);
    expect(fn () => $provider->assertSafeExternalUrl('https://apps.fortnox.se/oauth-v1/token'))
        ->not->toThrow(FortnoxProviderException::class);
});

test('default config allowlists fortnox api and oauth hosts', function () {
    $provider = new HttpFortnoxProvider([
        'client_id' => 'client',
        'client_secret' => 'secret',
    ]);

    expect(fn () => $provider->assertSafeExternalUrl('https://api.fortnox.se/3/articles'))
        ->not->toThrow(FortnoxProviderException::class)
        ->and(fn () => $provider->assertSafeExternalUrl('https://apps.fortnox.se/oauth-v1/token'))
        ->not->toThrow(FortnoxProviderException::class);
});

test('non https urls are rejected', function (string $url) {
    expect(fn () => fortnoxHttpProvider()->assertSafeExternalUrl($url))
        ->toThrow(FortnoxProviderException::class, 'Fortnox URLs must use HTTPS.');
})->with([
    'plain http' => ['http://api.fortnox.se'],
    'ftp scheme' => ['ftp://api.fortnox.se'],
]);

test('empty fortnox urls are rejected', function () {
    expect(fn () => fortnoxHttpProvider()->assertSafeExternalUrl(''))
        ->toThrow(FortnoxProviderException::class, 'Fortnox URL is not configured.');
});

test('non allowlisted hosts are rejected', function (string $url) {
    expect(fn () => fortnoxHttpProvider()->assertSafeExternalUrl($url))
        ->toThrow(FortnoxProviderException::class);
})->with([
    'other domain' => ['https://evil.example.com'],
    'host with userinfo' => ['https://api.fortnox.se@evil.example.com'],
    'missing host' => ['https://'],
]);

test('private and reserved ip literals are rejected', function (string $url) {
    expect(fn () => fortnoxHttpProvider()->assertSafeExternalUrl($url))
        ->toThrow(FortnoxProviderException::class, 'Fortnox URLs must not target private or reserved addresses.');
})->with([
    'loopback' => ['https://127.0.0.1'],
    'private 10' => ['https://10.0.0.1'],
    'private 192.168' => ['https://192.168.1.10'],
    'link local' => ['https://169.254.169.254'],
    'ipv6 loopback' => ['https://[::1]'],
]);

test('public ip literals pass the guard', function () {
    expect(fn () => fortnoxHttpProvider()->assertSafeExternalUrl('https://8.8.8.8'))
        ->not->toThrow(FortnoxProviderException::class);
});

test('token exchange posts form credentials and returns the parsed token', function () {
    Http::fake([
        'apps.fortnox.se/*' => Http::response([
            'access_token' => 'real-access',
            'refresh_token' => 'real-refresh',
            'expires_in' => 3600,
            'scope' => 'article',
        ]),
    ]);

    $token = fortnoxHttpProvider()->exchangeAuthorizationCode('code', 'https://app.test/callback');

    expect($token)->toBeInstanceOf(FortnoxAccessToken::class)
        ->and($token->accessToken)->toBe('real-access')
        ->and($token->refreshToken)->toBe('real-refresh')
        ->and($token->expiresAt)->not->toBeNull();
    Http::assertSent(fn ($request) => $request->url() === 'https://apps.fortnox.se/oauth-v1/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'code');
});

test('token failures map to typed provider exceptions', function (int $status, string $class, bool $retryable) {
    Http::fake([
        'apps.fortnox.se/*' => Http::response(['error' => 'nope'], $status),
    ]);

    try {
        fortnoxHttpProvider()->exchangeAuthorizationCode('code', 'https://app.test/callback');
        expect(false)->toBeTrue('Expected an exception.');
    } catch (FortnoxProviderException $exception) {
        expect($exception->retryable)->toBe($retryable)
            ->and($exception->status)->toBe($status);
    }
})->with([
    'unauthorized' => [401, FortnoxProviderException::class, false],
    'forbidden' => [403, FortnoxProviderException::class, false],
    'rate limited' => [429, FortnoxProviderException::class, true],
    'server error' => [500, FortnoxProviderException::class, true],
    'not found' => [404, FortnoxProviderException::class, false],
]);

test('connection failures are retryable provider exceptions', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out', 0));

    try {
        fortnoxHttpProvider()->exchangeAuthorizationCode('code', 'https://app.test/callback');
        expect(false)->toBeTrue('Expected an exception.');
    } catch (FortnoxProviderException $exception) {
        expect($exception->retryable)->toBeTrue();
    }
});

test('missing client credentials are rejected before any request', function () {
    Http::fake();

    $provider = fortnoxHttpProvider(['client_id' => '', 'client_secret' => '']);

    try {
        $provider->exchangeAuthorizationCode('code', 'https://app.test/callback');
        expect(false)->toBeTrue('Expected an exception.');
    } catch (FortnoxProviderException $exception) {
        expect($exception->retryable)->toBeFalse();
    }
    Http::assertNothingSent();
});

test('article listing parses fortnox payload and paginates', function () {
    Http::fake([
        'api.fortnox.se/*' => Http::response([
            'Articles' => [
                ['@url' => 'u1', 'ArticleNumber' => 'A-1', 'Description' => 'One', 'SalesPrice' => '10.5', 'Stock' => '2', 'EAN' => '7312345678909', '@lastModified' => '2026-08-20T10:00:00Z'],
                ['@url' => 'u2', 'ArticleNumber' => 'A-2', 'Description' => 'Two', '@lastModified' => '2026-08-20T10:00:00Z'],
            ],
            'MetaInformation' => ['@CurrentPage' => 1, '@TotalPages' => 2],
        ]),
    ]);

    $page = fortnoxHttpProvider()->listArticles('real-access', null, null);

    expect($page->articles)->toHaveCount(2)
        ->and($page->articles[0]->number)->toBe('A-1')
        ->and($page->articles[0]->price)->toBe(10.5)
        ->and($page->articles[0]->stock)->toBe(2.0)
        ->and($page->articles[0]->gtin)->toBe('7312345678909')
        ->and($page->nextPage)->toBe('2');
    Http::assertSent(fn ($request) => $request->url() === 'https://api.fortnox.se/3/articles?limit=2');
});

test('article listing sends lastmodified as search parameter for incremental sync', function () {
    Http::fake([
        'api.fortnox.se/*' => Http::response([
            'Articles' => [],
            'MetaInformation' => ['@CurrentPage' => 1, '@TotalPages' => 1],
        ]),
    ]);

    fortnoxHttpProvider()->listArticles('real-access', '2026-09-06T09:17:25Z', null);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.fortnox.se/3/articles?limit=2&lastmodified=2026-09-06%2009%3A17');
});

test('fortnox error information is included in provider exceptions', function () {
    Http::fake([
        'api.fortnox.se/*' => Http::response([
            'ErrorInformation' => [
                'message' => 'Ett ogiltigt filter har använts.',
                'code' => 2000587,
            ],
        ], 400),
    ]);

    expect(fn () => fortnoxHttpProvider()->listArticles('real-access', null, null))
        ->toThrow(FortnoxProviderException::class, 'Fortnox code 2000587. Ett ogiltigt filter har använts.');
});
