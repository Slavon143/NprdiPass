<?php

namespace Tests\Feature\Analytics;

use App\Enums\Analytics\AnalyticsDeviceCategory;
use App\Enums\Analytics\AnalyticsEventType;
use App\Enums\Analytics\AnalyticsReferrerCategory;
use App\Jobs\Analytics\RecordAnalyticsEventJob;
use App\Models\Analytics\AnalyticsDailyAggregate;
use App\Models\Analytics\AnalyticsEvent;
use App\Models\Analytics\AnalyticsMonthlyAggregate;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Services\Analytics\AnalyticsAggregator;
use App\Services\Analytics\AnalyticsPruner;
use App\Services\Analytics\AnalyticsRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalyticsFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_passport_view_queues_sanitized_analytics_event_and_304_skips(): void
    {
        config(['features.analytics' => true]);
        Queue::fake();

        $passport = $this->publishedPassport();

        $response = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile Safari/604.1',
            'Referer' => 'https://www.google.com/search?q=nordipass',
            'CF-IPCountry' => 'se',
        ])->get(route('public.passports.show', ['publicId' => $passport->public_id]));

        $response->assertOk();

        Queue::assertPushed(RecordAnalyticsEventJob::class, function (RecordAnalyticsEventJob $job): bool {
            return $job->eventType === AnalyticsEventType::PassportView->value
                && $job->countryCode === 'SE'
                && $job->deviceCategory === AnalyticsDeviceCategory::Mobile->value
                && $job->referrerCategory === AnalyticsReferrerCategory::Search->value
                && $job->botFlag === false;
        });

        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get(route('public.passports.show', ['publicId' => $passport->public_id]))
            ->assertStatus(304);

        Queue::assertPushed(RecordAnalyticsEventJob::class, 1);
    }

    public function test_job_records_event_without_raw_ip_user_agent_or_referrer_storage(): void
    {
        config(['features.analytics' => true]);

        $passport = $this->publishedPassport();

        $job = new RecordAnalyticsEventJob(
            $passport->public_id,
            AnalyticsEventType::PassportView->value,
            'NO',
            AnalyticsDeviceCategory::Desktop->value,
            AnalyticsReferrerCategory::External->value,
            false,
            CarbonImmutable::parse('2026-09-19 10:00:00')->toIso8601String(),
        );

        $job->handle(app(AnalyticsRecorder::class));

        $this->assertDatabaseHas('analytics_events', [
            'company_id' => $passport->company_id,
            'product_id' => $passport->product_id,
            'published_version_id' => $passport->current_published_version_id,
            'passport_public_id' => $passport->public_id,
            'event_type' => AnalyticsEventType::PassportView->value,
            'country_code' => 'NO',
            'device_category' => AnalyticsDeviceCategory::Desktop->value,
            'referrer_category' => AnalyticsReferrerCategory::External->value,
            'bot_flag' => false,
        ]);

        $this->assertFalse(Schema::hasColumn('analytics_events', 'ip_address'));
        $this->assertFalse(Schema::hasColumn('analytics_events', 'user_agent'));
        $this->assertFalse(Schema::hasColumn('analytics_events', 'referrer_url'));
    }

    public function test_aggregates_dedupe_repeat_events_and_exclude_bots_idempotently(): void
    {
        config(['features.analytics' => true]);
        config(['analytics.dedupe_seconds' => 60]);

        $passport = $this->publishedPassport();
        $base = CarbonImmutable::parse('2026-09-19 10:00:00');

        $this->event($passport, $base);
        $this->event($passport, $base->addSeconds(30), referrerCategory: AnalyticsReferrerCategory::External);
        $this->event($passport, $base->addSeconds(61));
        $this->event($passport, $base->addSeconds(62), bot: true);

        $aggregator = app(AnalyticsAggregator::class);
        $aggregator->aggregateDay($base);
        $aggregator->aggregateMonth($base);
        $aggregator->aggregateDay($base);
        $aggregator->aggregateMonth($base);

        $this->assertSame(1, AnalyticsDailyAggregate::query()->count());
        $this->assertSame(1, AnalyticsMonthlyAggregate::query()->count());
        $this->assertSame(2, AnalyticsDailyAggregate::query()->firstOrFail()->event_count);
        $this->assertSame(2, AnalyticsMonthlyAggregate::query()->firstOrFail()->event_count);
    }

    public function test_pruner_applies_raw_bot_and_daily_aggregate_retention(): void
    {
        config([
            'analytics.raw_retention_days' => 90,
            'analytics.bot_retention_days' => 30,
            'analytics.daily_retention_days' => 1095,
        ]);

        $passport = $this->publishedPassport();
        $now = CarbonImmutable::parse('2026-09-19 12:00:00');

        $this->event($passport, $now->subDays(91));
        $this->event($passport, $now->subDays(31), bot: true);
        $this->event($passport, $now->subDays(10));
        $this->event($passport, $now->subDays(10), bot: true);

        AnalyticsDailyAggregate::query()->create([
            'company_id' => $passport->company_id,
            'product_id' => $passport->product_id,
            'published_version_id' => $passport->current_published_version_id,
            'passport_public_id' => $passport->public_id,
            'event_type' => AnalyticsEventType::PassportView->value,
            'aggregate_date' => $now->subDays(1096)->toDateString(),
            'country_code' => 'SE',
            'device_category' => AnalyticsDeviceCategory::Mobile->value,
            'referrer_category' => AnalyticsReferrerCategory::Direct->value,
            'event_count' => 1,
        ]);

        $deleted = app(AnalyticsPruner::class)->prune($now);

        $this->assertSame(['bot_events' => 1, 'raw_events' => 1, 'daily_aggregates' => 1], $deleted);
        $this->assertSame(2, AnalyticsEvent::query()->count());
        $this->assertSame(0, AnalyticsDailyAggregate::query()->count());
    }

    private function publishedPassport(): ProductPassport
    {
        $passport = ProductPassport::factory()->published()->create();

        $version = ProductPassportVersion::factory()->published()->create([
            'company_id' => $passport->company_id,
            'passport_id' => $passport->getKey(),
            'payload' => [
                '_catalog_context' => [
                    'product' => [
                        'name' => 'Analytics Test Product',
                        'brand' => 'NordiPass',
                    ],
                ],
                'enabled_sections' => [],
                'data' => [],
                'translations' => [],
            ],
        ]);

        $passport->forceFill(['current_published_version_id' => $version->getKey()])->save();

        return $passport->fresh(['currentPublishedVersion']);
    }

    private function event(
        ProductPassport $passport,
        CarbonImmutable $occurredAt,
        AnalyticsReferrerCategory $referrerCategory = AnalyticsReferrerCategory::Direct,
        bool $bot = false,
    ): AnalyticsEvent {
        return AnalyticsEvent::query()->create([
            'company_id' => $passport->company_id,
            'product_id' => $passport->product_id,
            'published_version_id' => $passport->current_published_version_id,
            'passport_public_id' => $passport->public_id,
            'event_type' => AnalyticsEventType::PassportView->value,
            'country_code' => 'SE',
            'device_category' => AnalyticsDeviceCategory::Mobile->value,
            'referrer_category' => $referrerCategory->value,
            'bot_flag' => $bot,
            'occurred_at' => $occurredAt,
        ]);
    }
}
