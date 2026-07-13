<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

test('api limiter is defined', function () {
    expect(RateLimiter::limiter('api-authenticated'))->not->toBeNull();
});

test('api-public limiter is defined', function () {
    expect(RateLimiter::limiter('api-public'))->not->toBeNull();
});

test('auth limiter is defined', function () {
    expect(RateLimiter::limiter('auth'))->not->toBeNull();
});

test('invitations limiter is defined', function () {
    expect(RateLimiter::limiter('invitations.manage'))->not->toBeNull()
        ->and(RateLimiter::limiter('invitations.verify'))->not->toBeNull()
        ->and(RateLimiter::limiter('invitations.accept'))->not->toBeNull();
});

test('api-token-management limiter is defined', function () {
    expect(RateLimiter::limiter('api-token-management'))->not->toBeNull();
});

test('rate limit config values are integers', function () {
    expect(Config::get('rate_limits.api.per_minute'))->toBeInt()
        ->and(Config::get('rate_limits.api_public.per_minute'))->toBeInt()
        ->and(Config::get('rate_limits.auth.per_minute'))->toBeInt()
        ->and(Config::get('rate_limits.token_management.create_per_minute'))->toBeInt();
});
