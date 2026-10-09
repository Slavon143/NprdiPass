<?php

namespace App\Jobs\Infrastructure;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class QueueWorkerHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        Cache::forever(config('health.worker_heartbeat_key', 'nordipass:infrastructure:worker:last_run'), now()->toIso8601String());
    }
}
