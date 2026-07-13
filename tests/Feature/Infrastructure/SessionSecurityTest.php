<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Config;

test('session HttpOnly is enabled', function () {
    expect(Config::get('session.http_only'))->toBe(true);
});

test('session SameSite is lax', function () {
    expect(Config::get('session.same_site'))->toBe('lax');
});

test('session secure cookie is false or unset for local HTTP', function () {
    $secure = Config::get('session.secure');

    expect($secure === null || $secure === false)->toBeTrue();
});

test('session encryption is disabled by default', function () {
    expect(Config::get('session.encrypt'))->toBe(false);
});

test('session serialization is JSON to prevent PHP gadget chains', function () {
    expect(Config::get('session.serialization'))->toBe('json');
});

test('session config allows separate per-environment secure cookie', function () {
    $secure = env('SESSION_SECURE_COOKIE', false);

    expect(in_array($secure, [false, true, null], true))->toBeTrue();
});
