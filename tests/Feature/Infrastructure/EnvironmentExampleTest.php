<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Config;

test('.env.example file exists', function () {
    expect(base_path('.env.example'))->toBeFile();
});

test('.env.example contains required application keys', function () {
    $content = file_get_contents(base_path('.env.example'));

    expect($content)->toContain('APP_NAME=NordiPass')
        ->and($content)->toContain('APP_KEY=')
        ->and($content)->toContain('APP_ENV=local')
        ->and($content)->toContain('APP_DEBUG=true')
        ->and($content)->toContain('APP_URL=http://localhost:8000')
        ->and($content)->toContain('APP_TIMEZONE=UTC')
        ->and($content)->toContain('DB_CONNECTION=mysql')
        ->and($content)->toContain('FILESYSTEM_DISK=local')
        ->and($content)->toContain('TENANCY_SESSION_KEY=nordipass.current_company_id');
});

test('.env.example has no real secrets', function () {
    $content = file_get_contents(base_path('.env.example'));

    expect($content)->not->toContain('sk_live_')
        ->and($content)->not->toContain('sk_test_')
        ->and($content)->not->toContain('password');
});

test('config file defaults to mysql connection', function () {
    $config = file_get_contents(base_path('config/database.php'));

    expect($config)->toContain("'default' => env('DB_CONNECTION', 'mysql')")
        ->and(Config::get('database.default'))->toBe('mysql')
        ->and((string) Config::get('database.connections.mysql.database'))->toEndWith('_testing');
});

test('default filesystem is local', function () {
    expect(Config::get('filesystems.default'))->toBe('local');
});

test('boolean config values cast correctly', function () {
    Config::set('audit.ip_storage', false);
    expect(Config::get('audit.ip_storage'))->toBeFalse();

    Config::set('audit.ip_storage', true);
    expect(Config::get('audit.ip_storage'))->toBeTrue();

    Config::set('api.allow_non_expiring_tokens', false);
    expect(Config::get('api.allow_non_expiring_tokens'))->toBeFalse();

    Config::set('api.allow_non_expiring_tokens', true);
    expect(Config::get('api.allow_non_expiring_tokens'))->toBeTrue();

    Config::set('api.prune_inactive_user_tokens', false);
    expect(Config::get('api.prune_inactive_user_tokens'))->toBeFalse();

    Config::set('api.prune_inactive_user_tokens', true);
    expect(Config::get('api.prune_inactive_user_tokens'))->toBeTrue();
});

test('integer config values return as integers', function () {
    $retentionDays = Config::get('audit.retention_days');
    expect($retentionDays)->toBeInt();

    $expiresHours = Config::get('invitations.expires_hours');
    expect($expiresHours)->toBeInt();
});

test('application timezone is UTC', function () {
    expect(Config::get('app.timezone'))->toBe('UTC');
});
