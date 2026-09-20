<?php

namespace App\Services\Analytics;

use App\Models\Analytics\AnalyticsDailyAggregate;
use App\Models\Analytics\AnalyticsEvent;
use App\Models\Analytics\AnalyticsMonthlyAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AnalyticsAggregator
{
    public function aggregateDay(CarbonImmutable $date): int
    {
        $start = $date->startOfDay();
        $end = $date->endOfDay();
        $rows = $this->aggregateRows($start, $end);

        AnalyticsDailyAggregate::query()
            ->whereDate('aggregate_date', $start->toDateString())
            ->delete();

        foreach ($rows as $row) {
            AnalyticsDailyAggregate::query()->create($row + [
                'aggregate_date' => $start->toDateString(),
            ]);
        }

        return count($rows);
    }

    public function aggregateMonth(CarbonImmutable $month): int
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $rows = $this->aggregateRows($start, $end);

        AnalyticsMonthlyAggregate::query()
            ->whereDate('aggregate_month', $start->toDateString())
            ->delete();

        foreach ($rows as $row) {
            AnalyticsMonthlyAggregate::query()->create($row + [
                'aggregate_month' => $start->toDateString(),
            ]);
        }

        return count($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aggregateRows(CarbonImmutable $start, CarbonImmutable $end): array
    {
        /** @var Collection<int, AnalyticsEvent> $events */
        $events = AnalyticsEvent::query()
            ->where('bot_flag', false)
            ->whereBetween('occurred_at', [$start, $end])
            ->orderBy('occurred_at')
            ->get();

        $dedupeSeconds = max(1, (int) config('analytics.dedupe_seconds', 60));
        $lastSeen = [];
        $counts = [];

        foreach ($events as $event) {
            $dedupeKey = implode('|', [
                $event->event_type->value,
                $event->passport_public_id,
                $event->country_code,
                $event->device_category->value,
            ]);
            $occurredAt = $event->occurred_at;

            if (isset($lastSeen[$dedupeKey]) && ($occurredAt->getTimestamp() - $lastSeen[$dedupeKey]->getTimestamp()) < $dedupeSeconds) {
                continue;
            }

            $lastSeen[$dedupeKey] = $occurredAt;

            $countKey = implode('|', [
                $event->company_id,
                $event->product_id,
                $event->published_version_id ?? 'null',
                $event->passport_public_id,
                $event->event_type->value,
                $event->country_code,
                $event->device_category->value,
                $event->referrer_category->value,
            ]);

            if (! isset($counts[$countKey])) {
                $counts[$countKey] = [
                    'company_id' => $event->company_id,
                    'product_id' => $event->product_id,
                    'published_version_id' => $event->published_version_id,
                    'passport_public_id' => $event->passport_public_id,
                    'event_type' => $event->event_type->value,
                    'country_code' => $event->country_code,
                    'device_category' => $event->device_category->value,
                    'referrer_category' => $event->referrer_category->value,
                    'event_count' => 0,
                ];
            }

            $counts[$countKey]['event_count']++;
        }

        return array_values($counts);
    }
}
