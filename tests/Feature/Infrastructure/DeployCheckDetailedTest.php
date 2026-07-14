<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('app.env', 'testing');
    Config::set('app.debug', true);
    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Config::set('database.default', 'mysql');
    Config::set('database.connections.mysql.database', 'nordipass_testing');
});

afterEach(function () {
    Config::set('app.env', 'testing');
    Config::set('app.debug', true);
    Config::set('database.default', 'mysql');
});

test('deploy-check detects missing APP_KEY', function () {
    Config::set('app.key', '');

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(1);
});

test('deploy-check fails when APP_DEBUG=true in production', function () {
    Config::set('app.env', 'production');
    Config::set('app.debug', true);

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(1);
});

test('deploy-check fails on database failure', function () {
    Config::set('database.default', 'nonexistent_connection');

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(1);
});

test('deploy-check is read-only and does not modify config files', function () {
    $configPath = config_path('app.php');

    if (! file_exists($configPath)) {
        $this->markTestSkipped('Config file not found.');
    }

    $originalHash = md5_file($configPath);

    Artisan::call('nordipass:deploy-check');

    expect(md5_file($configPath))->toBe($originalHash);
});

test('deploy-check does not print secrets', function () {
    Artisan::call('nordipass:deploy-check');
    $output = Artisan::output();

    expect($output)->not->toContain('DB_PASSWORD')
        ->and($output)->not->toMatch('/base64:[A-Za-z0-9+\/=]{44}/');
});

test('valid testing configuration passes', function () {
    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(0);
});

test('deploy-check warns when trusted hosts uses localhost defaults in production', function () {
    Config::set('app.env', 'production');
    Config::set('app.debug', false);
    Config::set('security.trusted_hosts', 'localhost,127.0.0.1');

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(1);
});

test('deploy-check passes with production secure config', function () {
    Config::set('app.env', 'production');
    Config::set('app.debug', false);
    Config::set('app.url', 'https://nordipass.example.com');
    Config::set('session.secure', true);
    Config::set('security.trusted_hosts', 'nordipass.example.com');
    Config::set('security.trusted_proxies', '10.0.0.0/8');
    Config::set('health.require_scheduler', true);
    Config::set('security.hsts_enabled', true);

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(0);
});
