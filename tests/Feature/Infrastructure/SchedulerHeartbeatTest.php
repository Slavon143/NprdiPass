<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;

test('heartbeat command writes UTC timestamp to cache', function () {
    Cache::forget('nordipass:infrastructure:scheduler:last_run');

    $exitCode = Artisan::call('nordipass:scheduler-heartbeat');

    expect($exitCode)->toBe(0);

    $stored = Cache::get('nordipass:infrastructure:scheduler:last_run');
    expect($stored)->not->toBeNull()
        ->and((string) $stored)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

test('heartbeat command is idempotent', function () {
    Artisan::call('nordipass:scheduler-heartbeat');
    $first = Cache::get('nordipass:infrastructure:scheduler:last_run');

    sleep(1);
    Artisan::call('nordipass:scheduler-heartbeat');
    $second = Cache::get('nordipass:infrastructure:scheduler:last_run');

    expect($second)->not->toBe($first);
    expect($second)->toBeGreaterThan($first);
});

test('heartbeat schedule exists once', function () {
    $events = Schedule::events();
    $count = 0;

    foreach ($events as $event) {
        if ($event->command && str_contains($event->command, 'nordipass:scheduler-heartbeat')) {
            $count++;
            expect($event->expression)->toBe('* * * * *');
        }
    }

    expect($count)->toBe(1);
});

test('fresh heartbeat passes readiness when required', function () {
    Config::set('health.require_scheduler', true);
    Cache::forever('nordipass:infrastructure:scheduler:last_run', now()->toIso8601String());

    $response = $this->getJson('/ready');

    $response->assertStatus(200);
});

test('stale heartbeat returns 503 when required', function () {
    Config::set('health.require_scheduler', true);
    Cache::forever('nordipass:infrastructure:scheduler:last_run', now()->subMinutes(10)->toIso8601String());

    $response = $this->getJson('/ready');

    $response->assertStatus(503);
});

test('missing heartbeat returns 503 when required', function () {
    Config::set('health.require_scheduler', true);
    Cache::forget('nordipass:infrastructure:scheduler:last_run');

    $response = $this->getJson('/ready');

    $response->assertStatus(503);
});

test('scheduler requirement can be disabled locally', function () {
    Config::set('health.require_scheduler', false);
    Cache::forget('nordipass:infrastructure:scheduler:last_run');

    $response = $this->getJson('/ready');

    $response->assertStatus(200);
});
