<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Config::set('app.env', 'production');
    Config::set('app.debug', false);
    Config::set('app.url', 'https://fixture.invalid');
    Config::set('session.secure', true);
    Config::set('security.trusted_hosts', 'fixture.invalid');
    Config::set('security.trusted_proxies', '127.0.0.1');
    Config::set('security.hsts_enabled', true);
    Config::set('health.require_scheduler', true);
    Config::set('health.require_async_queue', false);
    Config::set('backup.enabled', true);
    Config::set('backup.disk', 'local');
    Config::set('backup.path', 'preflight-fixture');
    Config::set('backup.max_age_hours', 26);
    $this->disk = Storage::fake('local');
    $this->directory = 'preflight-fixture/11111111-1111-4111-8111-111111111111';
    $this->manifest = ['version' => 2, 'status' => 'complete', 'created_at' => now()->toIso8601String(),
        'scope' => ['database' => true, 'files' => true], 'artifacts' => []];
    foreach (['database' => 'database.sql.gz', 'files' => 'files.tar.gz'] as $type => $name) {
        $this->disk->put($this->directory.'/'.$name, 'fixture');
        $this->manifest['artifacts'][$type] = ['path' => $name, 'size' => 7, 'sha256' => hash('sha256', 'fixture')];
    }
    $this->disk->put($this->directory.'/manifest.json', json_encode($this->manifest, JSON_THROW_ON_ERROR));
});

test('production preflight accepts complete fresh nested backup metadata', function () {
    $this->artisan('nordipass:deploy-check')->assertSuccessful();
});

test('production preflight rejects the exact broken backup condition', function (string $condition) {
    if ($condition === 'missing') {
        $this->disk->deleteDirectory($this->directory);
    } elseif ($condition === 'disabled') {
        Config::set('backup.enabled', false);
    } elseif ($condition === 'unavailable') {
        Storage::shouldReceive('disk')->once()->andThrow(new \RuntimeException('fixture-secret'));
    } elseif ($condition === 'missing-artifact') {
        $this->disk->delete($this->directory.'/database.sql.gz');
    } elseif ($condition === 'size-mismatch') {
        $this->disk->put($this->directory.'/database.sql.gz', 'changed fixture');
    } else {
        match ($condition) {
            'stale' => $this->manifest['created_at'] = now()->subHours(30)->toIso8601String(),
            'future' => $this->manifest['created_at'] = now()->addHour()->toIso8601String(),
            'invalid-date' => $this->manifest['created_at'] = 'not-a-date',
            'incomplete' => $this->manifest['status'] = 'incomplete',
            'files-only' => $this->manifest['scope']['database'] = false,
            'bad-hash' => $this->manifest['artifacts']['database']['sha256'] = 'invalid',
            'traversal' => $this->manifest['artifacts']['database']['path'] = '../outside.sql.gz',
        };
        $this->disk->put($this->directory.'/manifest.json', $condition === 'invalid-json' ? '{' : json_encode($this->manifest, JSON_THROW_ON_ERROR));
    }
    $exit = Artisan::call('nordipass:deploy-check');
    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('✗ Backup freshness')->not->toContain('fixture-secret');
})->with(['missing', 'disabled', 'unavailable', 'stale', 'future', 'invalid-date',
    'incomplete', 'files-only', 'bad-hash', 'traversal', 'missing-artifact', 'size-mismatch']);

test('production preflight finds fresh backup by timestamp rather than UUID order', function () {
    $this->manifest['created_at'] = now()->subHours(30)->toIso8601String();
    $this->disk->put('preflight-fixture/ffffffff-ffff-4fff-8fff-ffffffffffff/manifest.json', json_encode($this->manifest, JSON_THROW_ON_ERROR));
    $this->artisan('nordipass:deploy-check')->assertSuccessful();
});

test('production preflight isolates each security or queue failure', function (string $key, mixed $value, string $label) {
    Config::set($key, $value);
    if ($key === 'queue.default') {
        Config::set('health.require_async_queue', true);
    }
    expect(Artisan::call('nordipass:deploy-check'))->toBe(1)
        ->and(Artisan::output())->toContain('✗ '.$label)
        ->toContain('Checked: 15 passed, 1 failed.');
})->with([
    ['app.key', '', 'APP_KEY is configured'],
    ['session.secure', false, 'Session secure cookie is enabled'],
    ['security.hsts_enabled', false, 'HSTS is enabled in production'],
    ['queue.default', 'sync', 'Queue connection is not sync when async required'],
]);

test('development keeps backups optional without inventing production readiness', function () {
    Config::set('app.env', 'testing');
    Config::set('backup.enabled', false);
    $this->artisan('nordipass:deploy-check')->assertSuccessful();
});
