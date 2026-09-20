<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsPruner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AnalyticsPruneCommand extends Command
{
    protected $signature = 'analytics:prune';

    protected $description = 'Prune privacy-safe analytics events and expired daily aggregates.';

    public function handle(AnalyticsPruner $pruner): int
    {
        if (! config('features.analytics', false)) {
            $this->components->info('Analytics feature is disabled.');

            return self::SUCCESS;
        }

        $deleted = $pruner->prune(CarbonImmutable::now());

        $this->components->info(sprintf(
            'Analytics retention applied: %d bot events, %d raw events, %d daily aggregates deleted.',
            $deleted['bot_events'],
            $deleted['raw_events'],
            $deleted['daily_aggregates'],
        ));

        return self::SUCCESS;
    }
}
