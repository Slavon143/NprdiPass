<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

test('/up returns 200 without authentication', function () {
    $response = $this->get('/up');

    $response->assertStatus(200);
});

test('/up does not require db or session', function () {
    $response = $this->get('/up');

    $response->assertStatus(200);
});

test('/ready returns 200 when required checks pass', function () {
    $response = $this->getJson('/ready');

    $response->assertStatus(200)
        ->assertJsonStructure(['status', 'timestamp']);
});

test('health response has no sensitive details', function () {
    $response = $this->getJson('/ready');

    $response->assertStatus(200)
        ->assertJsonMissing(['database_host', 'database_name', 'exception', 'trace']);
});

test('/ready returns UTC timestamp', function () {
    $response = $this->getJson('/ready');

    $data = $response->json();
    expect($data['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

test('/ready does not require authentication', function () {
    $response = $this->getJson('/ready');

    $response->assertStatus(200);
});

test('scheduler heartbeat command writes timestamp', function () {
    Cache::forget('nordipass:infrastructure:scheduler:last_run');

    $exitCode = Artisan::call('nordipass:scheduler-heartbeat');

    expect($exitCode)->toBe(0);
    expect(Cache::get('nordipass:infrastructure:scheduler:last_run'))->not->toBeNull();
});

test('stale scheduler heartbeat fails readiness when required', function () {
    Config::set('health.require_scheduler', true);
    Cache::forever('nordipass:infrastructure:scheduler:last_run', now()->subMinutes(5)->toIso8601String());

    $response = $this->getJson('/ready');

    $response->assertStatus(503);
});

test('fresh scheduler heartbeat passes readiness when required', function () {
    Config::set('health.require_scheduler', true);
    Cache::forever('nordipass:infrastructure:scheduler:last_run', now()->toIso8601String());

    $response = $this->getJson('/ready');

    $response->assertStatus(200);
});

test('scheduler requirement can be disabled for local', function () {
    Config::set('health.require_scheduler', false);
    Cache::forget('nordipass:infrastructure:scheduler:last_run');

    $response = $this->getJson('/ready');

    $response->assertStatus(200);
});
