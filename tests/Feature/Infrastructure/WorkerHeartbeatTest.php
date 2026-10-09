<?php

use App\Jobs\Infrastructure\QueueWorkerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

test('queue worker heartbeat is written by the queued job', function () {
    Cache::forget('nordipass:infrastructure:worker:last_run');

    (new QueueWorkerHeartbeat)->handle();

    expect(Cache::get('nordipass:infrastructure:worker:last_run'))->not->toBeNull();
});

test('readiness can require a fresh worker heartbeat', function () {
    Config::set('health.require_worker', true);
    Config::set('health.worker_max_age', 180);
    Cache::forever('nordipass:infrastructure:worker:last_run', now()->subMinutes(10)->toIso8601String());

    $this->getJson('/ready')->assertServiceUnavailable();
});
