<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SchedulerHeartbeat extends Command
{
    protected $signature = 'nordipass:scheduler-heartbeat';

    protected $description = 'Record scheduler heartbeat timestamp in cache';

    public function handle(): int
    {
        $key = config('health.scheduler_heartbeat_key', 'nordipass:infrastructure:scheduler:last_run');

        Cache::forever($key, now()->toIso8601String());

        $this->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
