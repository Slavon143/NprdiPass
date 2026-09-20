<?php

namespace App\Services\Analytics;

use App\Models\Analytics\AnalyticsDailyAggregate;
use App\Models\Analytics\AnalyticsEvent;
use Carbon\CarbonImmutable;

class AnalyticsPruner
{
    /**
     * @return array{bot_events: int, raw_events: int, daily_aggregates: int}
     */
    public function prune(CarbonImmutable $now): array
    {
        $botEvents = AnalyticsEvent::query()
            ->where('bot_flag', true)
            ->where('occurred_at', '<', $now->subDays((int) config('analytics.bot_retention_days', 30)))
            ->delete();

        $rawEvents = AnalyticsEvent::query()
            ->where('bot_flag', false)
            ->where('occurred_at', '<', $now->subDays((int) config('analytics.raw_retention_days', 90)))
            ->delete();

        $dailyAggregates = AnalyticsDailyAggregate::query()
            ->where('aggregate_date', '<', $now->subDays((int) config('analytics.daily_retention_days', 1095))->toDateString())
            ->delete();

        return [
            'bot_events' => $botEvents,
            'raw_events' => $rawEvents,
            'daily_aggregates' => $dailyAggregates,
        ];
    }
}
