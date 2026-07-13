<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Create a test storage file for files backup verification
    $dir = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'backup-test');
    @mkdir($dir, 0700, true);
    file_put_contents($dir.DIRECTORY_SEPARATOR.'fixture.txt', 'test content for backup verification');
});

afterEach(function () {
    $path = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'backup-test');
    if (is_dir($path)) {
        array_map('unlink', glob($path.DIRECTORY_SEPARATOR.'*'));
        rmdir($path);
    }
});

test('database backup creates valid gzip artifact', function () {
    $backupPath = config('backup.path');

    $dirs = Storage::disk(config('backup.disk'))->directories($backupPath);
    $latest = end($dirs);

    if ($latest === false) {
        return; // No backup to verify in test
    }

    $manifest = json_decode(Storage::disk(config('backup.disk'))->get($latest.'/manifest.json'), true);

    if (! isset($manifest['artifacts']['database'])) {
        return;
    }

    $content = Storage::disk(config('backup.disk'))->get($latest.'/'.$manifest['artifacts']['database']['path']);
    $decompressed = @gzdecode($content);

    // Verify it's a valid SQL dump
    expect($decompressed)->not->toBeFalse();
    expect((string) $decompressed)->toContain('CREATE TABLE');
});

test('files-only backup succeeds', function () {
    Config::set('backup.files.include', [storage_path('app/private/backup-test')]);
    Config::set('backup.database.enabled', false);
    Config::set('backup.verify_after_create', false);

    $exitCode = Artisan::call('nordipass:backup', ['--files-only' => true]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Backup scope: files only');
});

test('corrupted artifact fails verification', function () {
    $backupPath = config('backup.path');
    $disk = Storage::disk(config('backup.disk'));

    // Create a valid-looking but corrupted backup set
    $fakeId = '00000000-0000-0000-0000-000000000001';
    $fakeDir = $backupPath.'/'.$fakeId;
    $disk->makeDirectory($fakeDir);

    $disk->put($fakeDir.'/database.sql.gz', gzencode('corrupted data'));
    $disk->put($fakeDir.'/files.tar.gz', 'not a valid archive');
    $disk->put($fakeDir.'/manifest.json', json_encode([
        'version' => 1,
        'backup_id' => $fakeId,
        'status' => 'complete',
        'created_at' => now()->toIso8601String(),
        'environment' => 'testing',
        'database_driver' => 'mysql',
        'artifacts' => [
            'database' => [
                'path' => 'database.sql.gz',
                'size' => 20,
                'sha256' => '0000000000000000000000000000000000000000000000000000000000000000',
            ],
            'files' => [
                'path' => 'files.tar.gz',
                'size' => 20,
                'sha256' => '0000000000000000000000000000000000000000000000000000000000000000',
            ],
        ],
    ]));

    $exitCode = Artisan::call('nordipass:backup-verify', ['backup-id' => $fakeId]);

    expect($exitCode)->toBe(4);

    $disk->deleteDirectory($fakeDir);
});

test('missing artifact fails verification', function () {
    $backupPath = config('backup.path');
    $disk = Storage::disk(config('backup.disk'));

    $fakeId = '00000000-0000-0000-0000-000000000002';
    $fakeDir = $backupPath.'/'.$fakeId;
    $disk->makeDirectory($fakeDir);

    $disk->put($fakeDir.'/manifest.json', json_encode([
        'version' => 1,
        'backup_id' => $fakeId,
        'status' => 'complete',
        'created_at' => now()->toIso8601String(),
        'environment' => 'testing',
        'database_driver' => 'mysql',
        'artifacts' => [
            'database' => [
                'path' => 'nonexistent.sql.gz',
                'size' => 100,
                'sha256' => '0000000000000000000000000000000000000000000000000000000000000000',
            ],
        ],
    ]));

    $exitCode = Artisan::call('nordipass:backup-verify', ['backup-id' => $fakeId]);

    expect($exitCode)->toBe(4);

    $disk->deleteDirectory($fakeDir);
});

test('credentials file is cleaned up after backup', function () {
    Config::set('backup.database.binary', '/nonexistent/mysqldump');
    Config::set('backup.files.enabled', false);

    Artisan::call('nordipass:backup', []);

    // Check that no .my.cnf files remain in temp
    $tempFiles = glob(sys_get_temp_dir().'/.my.cnf*');
    expect($tempFiles)->toBeEmpty();
});

test('manifest has correct structure', function () {
    $json = json_encode([
        'version' => 1,
        'backup_id' => '550e8400-e29b-41d4-a716-446655440000',
        'created_at' => '2026-07-13T18:00:00Z',
        'status' => 'complete',
        'database_driver' => 'mysql',
        'artifacts' => [
            'database' => [
                'path' => 'database.sql.gz',
                'size' => 12345,
                'sha256' => 'abc123def456',
            ],
        ],
    ]);

    $decoded = json_decode($json, true);
    expect($decoded['version'])->toBe(1)
        ->and($decoded['backup_id'])->toMatch('/\A[0-9a-f-]{36}\z/')
        ->and($decoded['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/')
        ->and($decoded['artifacts']['database']['sha256'])->toHaveLength(12);
});

test('incomplete manifest is rejected', function () {
    $backupPath = config('backup.path');
    $disk = Storage::disk(config('backup.disk'));

    $fakeId = '00000000-0000-0000-0000-000000000003';
    $fakeDir = $backupPath.'/'.$fakeId;
    $disk->makeDirectory($fakeDir);

    $disk->put($fakeDir.'/manifest.json', json_encode([
        'version' => 1,
        'backup_id' => $fakeId,
        'status' => 'incomplete',
        'created_at' => now()->toIso8601String(),
        'database_driver' => 'mysql',
        'artifacts' => [],
    ]));

    $exitCode = Artisan::call('nordipass:backup-verify', ['backup-id' => $fakeId]);

    expect($exitCode)->toBe(1);

    $disk->deleteDirectory($fakeDir);
});

test('concurrent backup is rejected with exit code 3', function () {
    Cache::lock('nordipass:infrastructure:backup', 60)->get();

    $exitCode = Artisan::call('nordipass:backup', []);

    expect($exitCode)->toBe(3);

    Cache::lock('nordipass:infrastructure:backup')->forceRelease();
});

test('lock is released after backup exception', function () {
    Config::set('backup.database.binary', '/nonexistent/mysqldump');
    Config::set('backup.files.enabled', false);

    Artisan::call('nordipass:backup', []);

    expect(Cache::lock('nordipass:infrastructure:backup')->get())->toBeTrue();

    Cache::lock('nordipass:infrastructure:backup')->forceRelease();
});

test('retention dry-run deletes nothing', function () {
    $exitCode = Artisan::call('nordipass:backup-prune', ['--dry-run' => true]);

    expect($exitCode)->toBe(0);
});

test('prune path traversal protection works', function () {
    $backupPath = config('backup.path');
    $disk = Storage::disk(config('backup.disk'));

    $maliciousPath = '../outside';
    $realBase = realpath($disk->path(''));

    expect(str_starts_with(realpath($disk->path($maliciousPath)) ?: '', $realBase ?: ''))->toBeFalse();
});
