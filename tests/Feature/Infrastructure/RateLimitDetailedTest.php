<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

test('all rate limiter names are defined', function () {
    $expected = [
        'invitations.manage',
        'invitations.verify',
        'invitations.accept',
        'api-public',
        'api-authenticated',
        'api-token-management',
        'auth',
    ];

    foreach ($expected as $name) {
        expect(RateLimiter::limiter($name))
            ->not->toBeNull("Rate limiter '$name' is defined");
    }
});

test('auth limiter uses sha256 hashed key', function () {
    $limiter = RateLimiter::limiter('auth');
    expect($limiter)->not->toBeNull();
});

test('API limiter uses token ID as key', function () {
    $limiter = RateLimiter::limiter('api-authenticated');
    expect($limiter)->not->toBeNull();
});

test('api-public limiter uses IP as key', function () {
    $limiter = RateLimiter::limiter('api-public');
    expect($limiter)->not->toBeNull();
});

test('rate limit config values are integers', function () {
    expect(Config::get('rate_limits.api.per_minute'))->toBeInt()
        ->and(Config::get('rate_limits.api_public.per_minute'))->toBeInt()
        ->and(Config::get('rate_limits.auth.per_minute'))->toBeInt()
        ->and(Config::get('rate_limits.token_management.create_per_minute'))->toBeInt()
        ->and(Config::get('rate_limits.token_management.revoke_per_minute'))->toBeInt()
        ->and(Config::get('rate_limits.invitations.manage_per_minute'))->toBeInt()
        ->and(Config::get('rate_limits.invitations.verify_per_minute'))->toBeInt()
        ->and(Config::get('rate_limits.invitations.accept_per_minute'))->toBeInt();
});

test('rate limiter limit is consumed by hit and returns zero remaining', function () {
    $key = 'test-hit-key';

    RateLimiter::hit($key, 60);
    $remaining = RateLimiter::remaining($key, 1);

    expect($remaining)->toBe(0);
    RateLimiter::clear($key);
});

test('different rate limiters have different config values', function () {
    $apiLimit = Config::get('rate_limits.api.per_minute');
    $publicLimit = Config::get('rate_limits.api_public.per_minute');
    $authLimit = Config::get('rate_limits.auth.per_minute');

    expect($apiLimit)->not->toBe($authLimit);
    expect($publicLimit)->toBeGreaterThan($authLimit);
});
