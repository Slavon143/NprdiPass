<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AnalyticsAggregateCommand extends Command
{
    protected $signature = 'analytics:aggregate {--date=} {--month=}';

    protected $description = 'Build privacy-safe analytics aggregates from raw passport events.';

    public function handle(AnalyticsAggregator $aggregator): int
    {
        if (! config('features.analytics', false)) {
            $this->components->info('Analytics feature is disabled.');

            return self::SUCCESS;
        }

        $dateOption = $this->option('date');
        $monthOption = $this->option('month');

        $date = is_string($dateOption) && $dateOption !== ''
            ? CarbonImmutable::parse($dateOption)
            : CarbonImmutable::yesterday();

        $month = is_string($monthOption) && $monthOption !== ''
            ? CarbonImmutable::parse($monthOption)->startOfMonth()
            : $date->startOfMonth();

        $dailyRows = $aggregator->aggregateDay($date);
        $monthlyRows = $aggregator->aggregateMonth($month);

        $this->components->info("Analytics aggregates rebuilt: {$dailyRows} daily rows, {$monthlyRows} monthly rows.");

        return self::SUCCESS;
    }
}
