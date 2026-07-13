<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;

test('backup config loads correctly', function () {
    expect(config('backup.enabled'))->toBeTrue()
        ->and(config('backup.disk'))->toBe('local')
        ->and(config('backup.database.enabled'))->toBeTrue()
        ->and(config('backup.files.enabled'))->toBeTrue()
        ->and(config('backup.retention.daily'))->toBeInt()
        ->and(config('backup.retention.weekly'))->toBeInt()
        ->and(config('backup.retention.monthly'))->toBeInt()
        ->and(config('backup.lock_minutes'))->toBeInt();
});

test('backup dry-run shows configuration', function () {
    $exitCode = Artisan::call('nordipass:backup', ['--dry-run' => true]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('Disk');
});

test('backup disabled returns exit code 2', function () {
    Config::set('backup.enabled', false);

    $exitCode = Artisan::call('nordipass:backup', ['--dry-run' => true]);

    expect($exitCode)->toBe(2);
});

test('lock is released after command failure', function () {
    Config::set('backup.database.binary', '/nonexistent/mysqldump');
    Config::set('backup.files.enabled', false);

    Artisan::call('nordipass:backup', []);

    expect(Cache::lock('nordipass:infrastructure:backup')->get())->toBeTrue();

    Cache::lock('nordipass:infrastructure:backup')->forceRelease();
});

test('backup commands exist in application', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('nordipass:backup')
        ->and($commands)->toHaveKey('nordipass:backup-verify')
        ->and($commands)->toHaveKey('nordipass:backup-prune')
        ->and($commands)->toHaveKey('nordipass:restore')
        ->and($commands)->toHaveKey('nordipass:restore-verify');
});

test('backup is scheduled daily at 02:00 UTC', function () {
    $events = Schedule::events();
    $found = false;

    foreach ($events as $event) {
        if ($event->command && str_contains($event->command, 'nordipass:backup')
            && ! str_contains($event->command, 'prune')
            && ! str_contains($event->command, 'verify')) {
            $found = true;
            expect($event->expression)->toBe('0 2 * * *');
            expect($event->withoutOverlapping)->toBeGreaterThan(0);
            break;
        }
    }

    expect($found)->toBeTrue();
});

test('backup-prune is scheduled daily at 04:00 UTC', function () {
    $events = Schedule::events();
    $found = false;

    foreach ($events as $event) {
        if ($event->command && str_contains($event->command, 'nordipass:backup-prune')) {
            $found = true;
            expect($event->expression)->toBe('0 4 * * *');
            expect($event->withoutOverlapping)->toBeGreaterThan(0);
            break;
        }
    }

    expect($found)->toBeTrue();
});

test('retention values are positive integers', function () {
    expect(config('backup.retention.daily'))->toBeGreaterThan(0)
        ->and(config('backup.retention.weekly'))->toBeGreaterThan(0)
        ->and(config('backup.retention.monthly'))->toBeGreaterThan(0)
        ->and(config('backup.lock_minutes'))->toBeGreaterThan(0);
});

test('backup-prune dry-run does not fail', function () {
    $exitCode = Artisan::call('nordipass:backup-prune', ['--dry-run' => true]);

    expect($exitCode)->toBe(0);
});
