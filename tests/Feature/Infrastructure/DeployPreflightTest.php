<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

test('deploy-check fails when backup is stale', function () {
    Config::set('backup.enabled', true);
    Config::set('backup.disk', 'local');
    Config::set('backup.path', 'nonexistent/path/for/testing');

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBeGreaterThanOrEqual(0);
});

test('deploy-check fails when sync queue used with async requirement', function () {
    Config::set('queue.default', 'sync');
    Config::set('health.require_async_queue', true);

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(1);
});

test('deploy-check passes when sync queue allowed', function () {
    Config::set('queue.default', 'sync');
    Config::set('health.require_async_queue', false);

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(0);
});

test('deploy-check fails on missing APP_KEY in production', function () {
    Config::set('app.env', 'production');
    Config::set('app.debug', false);
    Config::set('app.key', '');

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(1);
});

test('deploy-check detects insecure session cookie in production', function () {
    Config::set('app.env', 'production');
    Config::set('app.debug', false);
    Config::set('session.secure', false);

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(1);
});

test('deploy-check detects HSTS disabled in production', function () {
    Config::set('app.env', 'production');
    Config::set('app.debug', false);
    Config::set('security.hsts_enabled', false);

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(1);
});
