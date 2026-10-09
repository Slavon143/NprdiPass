<?php

namespace App\Services\Observability;

use Illuminate\Database\Events\QueryExecuted;

class RequestTelemetry
{
    private bool $recording = false;

    private int $queryCount = 0;

    private float $queryMs = 0.0;

    private int $slowQueries = 0;

    private ?float $publicResolverMs = null;

    public function start(): void
    {
        $this->recording = true;
        $this->queryCount = 0;
        $this->queryMs = 0.0;
        $this->slowQueries = 0;
        $this->publicResolverMs = null;
    }

    public function stop(): void
    {
        $this->recording = false;
    }

    public function record(QueryExecuted $query): void
    {
        if (! $this->recording) {
            return;
        }

        $this->queryCount++;
        $this->queryMs += $query->time;

        if ($query->time >= (int) config('observability.slow_query_ms', 100)) {
            $this->slowQueries++;
        }
    }

    /**
     * @return array{query_count: int, query_ms: int, slow_queries: int, public_resolver_ms: float|null}
     */
    public function summary(): array
    {
        return [
            'query_count' => $this->queryCount,
            'query_ms' => (int) round($this->queryMs),
            'slow_queries' => $this->slowQueries,
            'public_resolver_ms' => $this->publicResolverMs,
        ];
    }

    public function recordPublicResolver(float $milliseconds): void
    {
        if ($this->recording) {
            $this->publicResolverMs = ($this->publicResolverMs ?? 0.0) + $milliseconds;
        }
    }
}
