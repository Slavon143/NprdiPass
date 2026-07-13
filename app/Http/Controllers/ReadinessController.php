<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReadinessController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! config('health.enabled', true)) {
            return response()->json([
                'status' => 'disabled',
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        $unavailable = [];
        $checks = [];

        if (config('health.require_database', true)) {
            $dbOk = $this->checkDatabase();
            $checks['database'] = $dbOk ? 'ok' : 'unavailable';
            if (! $dbOk) {
                $unavailable[] = 'database';
            }
        }

        if (config('health.require_cache', true)) {
            $cacheOk = $this->checkCache();
            $checks['cache'] = $cacheOk ? 'ok' : 'unavailable';
            if (! $cacheOk) {
                $unavailable[] = 'cache';
            }
        }

        if (config('health.require_queue', true)) {
            $queueOk = $this->checkQueue();
            $checks['queue'] = $queueOk ? 'ok' : 'unavailable';
            if (! $queueOk) {
                $unavailable[] = 'queue';
            }
        }

        if (config('health.require_scheduler', false)) {
            $schedulerOk = $this->checkScheduler();
            $checks['scheduler'] = $schedulerOk ? 'ok' : 'unavailable';
            if (! $schedulerOk) {
                $unavailable[] = 'scheduler';
            }
        }

        $allOk = count($unavailable) === 0;

        $response = [
            'status' => $allOk ? 'ok' : 'unavailable',
            'timestamp' => now()->toIso8601String(),
        ];

        if (config('health.details', false)) {
            $response['checks'] = $checks;
        }

        return response()->json($response, $allOk ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkCache(): bool
    {
        try {
            $key = 'nordipass:health:cache_check';
            Cache::put($key, true, 1);
            $result = Cache::get($key);
            Cache::forget($key);

            return $result === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkQueue(): bool
    {
        $connection = config('queue.default', 'sync');

        if ($connection === 'sync') {
            return ! config('health.require_async_queue', false);
        }

        if ($connection === 'database') {
            try {
                DB::select('SELECT 1');

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        if ($connection === 'redis') {
            try {
                $redis = app('redis');
                $redis->ping();

                return true;
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    private function checkScheduler(): bool
    {
        try {
            $key = config('health.scheduler_heartbeat_key', 'nordipass:infrastructure:scheduler:last_run');
            $heartbeat = Cache::get($key);

            if ($heartbeat === null) {
                return false;
            }

            $maxAge = (int) config('health.scheduler_max_age', 180);
            $timestamp = Carbon::parse($heartbeat);

            return $timestamp->diffInSeconds(now()) <= $maxAge;
        } catch (\Throwable) {
            return false;
        }
    }
}
