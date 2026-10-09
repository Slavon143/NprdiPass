<?php

namespace App\Services\Observability;

use App\Enums\Analytics\AnalyticsEventType;
use App\Enums\Jobs\JobStatusState;
use App\Models\Analytics\AnalyticsEvent;
use App\Models\Catalog\Product;
use App\Models\Jobs\JobStatus;
use App\Models\Passports\ProductPassportVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ObservabilitySnapshotService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $queue = $this->queue();
        $resolver = $this->publicResolver();
        $storage = $this->storage();
        $catalog = $this->catalog();

        $checks = [
            'queue_lag' => $queue['oldest_pending_seconds'] === null ? 'unknown' : $this->check($queue['oldest_pending_seconds'] <= $this->threshold('queue_lag_seconds')),
            'failed_jobs' => $queue['failed_jobs'] === null ? 'unknown' : $this->check($queue['failed_jobs'] <= $this->threshold('failed_jobs')),
            'public_resolver_latency' => $resolver['p95_ms'] === null ? 'unknown' : $this->check($resolver['p95_ms'] <= $this->threshold('public_resolver_p95_ms')),
            'storage_free' => $this->check($storage['free_percent'] >= $this->threshold('storage_free_percent')),
        ];

        return [
            'status' => $this->overallStatus($checks),
            'captured_at' => now()->toIso8601String(),
            'thresholds' => config('observability.slo'),
            'checks' => $checks,
            'queue' => $queue,
            'public_resolver' => $resolver,
            'storage' => $storage,
            'catalog' => $catalog,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function queue(): array
    {
        $settings = config('queue.connections.'.config('queue.default'), []);
        $databaseQueue = ($settings['driver'] ?? null) === 'database';
        $jobs = $databaseQueue ? DB::connection($settings['connection'] ?? null)->table($settings['table'] ?? 'jobs') : null;
        $oldestTimestamp = $jobs === null ? null : (clone $jobs)->whereNull('reserved_at')
            ->where('available_at', '<=', now()->timestamp)->min('available_at');
        $failedSettings = config('queue.failed', []);
        $failedCount = in_array($failedSettings['driver'] ?? null, ['database', 'database-uuids'], true)
            ? DB::connection($failedSettings['database'] ?? null)->table($failedSettings['table'] ?? 'failed_jobs')->count() : null;

        return [
            'connection' => (string) config('queue.default'),
            'pending_jobs' => $jobs?->count(),
            'failed_jobs' => $failedCount,
            'tracked_failed_jobs' => JobStatus::query()->where('status', JobStatusState::Failed->value)->count(),
            'tracked_running_jobs' => JobStatus::query()->where('status', JobStatusState::Running->value)->count(),
            'oldest_pending_seconds' => ! $databaseQueue ? null : ($oldestTimestamp === null ? 0 : (int) max(0, CarbonImmutable::createFromTimestamp((int) $oldestTimestamp)->diffInSeconds(now()))),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function publicResolver(): array
    {
        $views = AnalyticsEvent::query()
            ->where('event_type', AnalyticsEventType::PassportView->value)
            ->where('occurred_at', '>=', now()->subDay())
            ->count();

        return [
            'views_24h' => $views,
            'published_versions' => ProductPassportVersion::query()->published()->count(),
        ] + app(PublicResolverMetrics::class)->snapshot();
    }

    /**
     * @return array<string, int|string>
     */
    private function storage(): array
    {
        $path = (string) config('observability.storage_path', storage_path());
        $total = @disk_total_space($path) ?: 0;
        $free = @disk_free_space($path) ?: 0;

        return [
            'path' => $path,
            'free_bytes' => (int) $free,
            'total_bytes' => (int) $total,
            'free_percent' => $total > 0 ? (int) floor(($free / $total) * 100) : 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function catalog(): array
    {
        return [
            'products' => Product::query()->count(),
            'large_catalog_threshold' => 1000,
            'large_catalog_companies' => Product::query()
                ->select('company_id')
                ->groupBy('company_id')
                ->havingRaw('COUNT(*) >= ?', [1000])
                ->count(),
        ];
    }

    private function threshold(string $key): int
    {
        return (int) config("observability.slo.{$key}", 0);
    }

    private function check(bool $healthy): string
    {
        return $healthy ? 'ok' : 'breached';
    }

    /**
     * @param  array<string, string>  $checks
     */
    private function overallStatus(array $checks): string
    {
        if (in_array('breached', $checks, true)) {
            return 'degraded';
        }

        return in_array('unknown', $checks, true) ? 'unknown' : 'ok';
    }
}
