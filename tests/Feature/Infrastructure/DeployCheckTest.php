<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

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

    expect(is_dir($cachePath))->toBeTrue();
    expect(is_writable($cachePath))->toBeTrue();

    Artisan::call('nordipass:deploy-check');
    $output = Artisan::output();

    expect($output)->toContain('Bootstrap cache is writable');
});

test('deploy-check is read-only', function () {
    $configPath = config_path('app.php');
    $originalContent = file_get_contents($configPath);
    $migrationCount = DB::table('migrations')->count();

    Artisan::call('nordipass:deploy-check');

    expect(file_get_contents($configPath))->toBe($originalContent);
    expect(DB::table('migrations')->count())->toBe($migrationCount);
});
