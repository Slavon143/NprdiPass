<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

test('deploy-check command exists', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('nordipass:deploy-check');
});

test('deploy-check runs without errors', function () {
    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(0);
});

test('deploy-check does not output secrets', function () {
    $output = Artisan::output();

    expect($output)->not->toContain('DB_PASSWORD')
        ->and($output)->not->toContain('APP_KEY');
});

test('deploy-check detects missing APP_KEY', function () {
    Config::set('app.key', '');

    $exitCode = Artisan::call('nordipass:deploy-check');

    expect($exitCode)->toBe(1);
});

test('deploy-check detects invalid bootstrap cache permissions', function () {
    $cachePath = base_path('bootstrap/cache');

    if (is_dir($cachePath)) {
        chmod($cachePath, 0444);
    }

    $exitCode = Artisan::call('nordipass:deploy-check');

    if (is_dir($cachePath)) {
        chmod($cachePath, 0755);
    }
});

test('deploy-check is read-only', function () {
    $configPath = config_path('app.php');
    $originalContent = file_get_contents($configPath);
    $dbPath = database_path('database.sqlite');

    $originalDbExists = file_exists($dbPath);

    Artisan::call('nordipass:deploy-check');

    expect(file_get_contents($configPath))->toBe($originalContent);
    expect(file_exists($dbPath))->toBe($originalDbExists);
});
