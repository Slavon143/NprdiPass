<?php

namespace App\Services\Observability;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicResolverMetrics
{
    private const BOUNDS = [1, 5, 10, 25, 50, 100, 250, 500, 750, 1000, 2000, 5000, 10000];

    public function record(float $milliseconds): bool
    {
        if (! is_finite($milliseconds) || $milliseconds < 0) {
            return false;
        }
        try {
            $cache = $this->cache();
            if ($cache === null) {
                return false;
            }
            $key = $this->key(intdiv(now()->timestamp, 60));
            $store = $cache->getStore();
            if (! $store instanceof LockProvider) {
                return false;
            }
            $lock = $store->lock($key.':lock', 5);
            if (! $lock->get()) {
                return false;
            }
            try {
                $bucket = $cache->get($key, ['counts' => array_fill(0, count(self::BOUNDS) + 1, 0), 'max_ms' => 0]);
                $index = count(self::BOUNDS);
                foreach (self::BOUNDS as $candidate => $bound) {
                    if ($milliseconds <= $bound) {
                        $index = $candidate;
                        break;
                    }
                }
                $bucket['counts'][$index]++;
                $bucket['max_ms'] = max($bucket['max_ms'], (int) ceil($milliseconds));
                $stored = $cache->put($key, $bucket, 3660);
            } finally {
                $lock->release();
            }

            return $stored;
        } catch (Throwable) {
            // Monitoring failure must not change the passport response or expose
            // backend credentials through an exception message.
            Log::warning('observability.resolver_metrics_unavailable');

            return false;
        }
    }

    /** @return array{p95_ms: int|null, sample_count: int, p95_kind: string, collection_status: string, window_started_at: string} */
    public function snapshot(): array
    {
        $minute = intdiv(now()->timestamp, 60);
        $result = ['p95_ms' => null, 'sample_count' => 0, 'p95_kind' => 'histogram_upper_bound',
            'collection_status' => 'disabled', 'window_started_at' => now()->setTimestamp(($minute - 59) * 60)->toIso8601String()];
        try {
            $cache = $this->cache();
            if ($cache === null) {
                $result['collection_status'] = config('observability.resolver_metrics.enabled', false) ? 'unsupported' : 'disabled';

                return $result;
            }
            $counts = array_fill(0, count(self::BOUNDS) + 1, 0);
            $maximum = 0;
            for ($offset = 0; $offset < 60; $offset++) {
                $bucket = $cache->get($this->key($minute - $offset));
                if ($bucket === null) {
                    continue;
                }
                foreach ($bucket['counts'] as $index => $count) {
                    $counts[$index] += $count;
                }
                $maximum = max($maximum, $bucket['max_ms']);
            }
            $result['sample_count'] = array_sum($counts);
            $result['collection_status'] = $result['sample_count'] > 0 ? 'measured' : 'empty';
            $rank = (int) ceil($result['sample_count'] * 0.95);
            $seen = 0;
            foreach ($counts as $index => $count) {
                $seen += $count;
                if ($rank > 0 && $seen >= $rank) {
                    $result['p95_ms'] = self::BOUNDS[$index] ?? $maximum;
                    break;
                }
            }
        } catch (Throwable) {
            $result['p95_ms'] = null;
            $result['sample_count'] = 0;
            $result['collection_status'] = 'unavailable';
        }

        return $result;
    }

    private function cache(): ?Repository
    {
        if (! config('observability.resolver_metrics.enabled', false)) {
            return null;
        }
        $store = (string) config('observability.resolver_metrics.store', config('cache.default'));
        $driver = config('cache.stores.'.$store.'.driver');
        if (! in_array($driver, ['redis', 'database', 'file', 'memcached'], true)) {
            return null;
        }

        $cache = Cache::store($store);

        return $cache instanceof Repository ? $cache : null;
    }

    private function key(int $minute): string
    {
        return 'observability:resolver:histogram:v1:'.$minute;
    }
}
