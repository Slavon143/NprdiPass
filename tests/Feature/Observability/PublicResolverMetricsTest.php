<?php

use App\Http\Middleware\RecordRequestTelemetry;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Services\Observability\ObservabilitySnapshotService;
use App\Services\Observability\PublicResolverMetrics;
use App\Services\Passports\Public\PublicPassportResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['observability.resolver_metrics.enabled' => true, 'observability.resolver_metrics.store' => 'database']);
});

test('actual resolver execution reaches the collector when request logging is disabled', function () {
    config(['observability.request_logging_enabled' => false]);
    $passport = ProductPassport::factory()->published()->create();
    $version = ProductPassportVersion::factory()->published()->create([
        'company_id' => $passport->company_id, 'passport_id' => $passport->id, 'payload' => [],
    ]);
    $passport->forceFill(['current_published_version_id' => $version->id])->save();
    $response = app(RecordRequestTelemetry::class)->handle(
        Request::create('/p/'.$passport->public_id), function () use ($passport) {
            app(PublicPassportResolver::class)->resolve($passport->public_id);

            return response('resolved');
        },
    );
    expect($response->getStatusCode())->toBe(200)
        ->and(app(PublicResolverMetrics::class)->snapshot()['sample_count'])->toBe(1)
        ->and(app(PublicResolverMetrics::class)->snapshot()['p95_ms'])->toBeGreaterThan(0);
});

test('resolver histogram calculates nearest rank upper bound and expires old windows', function () {
    $this->travelTo(now()->startOfMinute());
    $metrics = app(PublicResolverMetrics::class);
    expect($metrics->snapshot()['p95_ms'])->toBeNull();
    for ($sample = 0; $sample < 19; $sample++) {
        expect($metrics->record(1.0))->toBeTrue();
    }
    $metrics->record(9000.0);
    expect((new PublicResolverMetrics)->snapshot()['p95_ms'])->toBe(1);
    $metrics->record(9000.0);
    $snapshot = $metrics->snapshot();
    expect($snapshot['sample_count'])->toBe(21)->and($snapshot['p95_ms'])->toBe(10000)
        ->and($snapshot['p95_kind'])->toBe('histogram_upper_bound');
    expect(app(ObservabilitySnapshotService::class)->snapshot()['checks']['public_resolver_latency'])->toBe('breached');
    $this->travel(60)->minutes();
    expect($metrics->snapshot()['sample_count'])->toBe(0)->and($metrics->snapshot()['p95_ms'])->toBeNull();
});

test('resolver metrics do not overwrite a bucket held by another collector', function () {
    $minute = intdiv(now()->timestamp, 60);
    $lock = Cache::store('database')->lock('observability:resolver:histogram:v1:'.$minute.':lock', 5);
    expect($lock->get())->toBeTrue();
    try {
        expect(app(PublicResolverMetrics::class)->record(10.0))->toBeFalse();
    } finally {
        $lock->release();
    }
    expect(app(PublicResolverMetrics::class)->record(10.0))->toBeTrue()
        ->and(app(PublicResolverMetrics::class)->snapshot()['sample_count'])->toBe(1);
});

test('disabled unsupported and failed metric stores remain unknown without throwing', function () {
    $metrics = app(PublicResolverMetrics::class);
    config(['observability.resolver_metrics.enabled' => false]);
    expect($metrics->snapshot()['collection_status'])->toBe('disabled')->and($metrics->record(10.0))->toBeFalse();
    config(['observability.resolver_metrics.enabled' => true, 'observability.resolver_metrics.store' => 'array']);
    expect($metrics->snapshot()['collection_status'])->toBe('unsupported')->and($metrics->record(10.0))->toBeFalse();
    config(['observability.resolver_metrics.store' => 'broken_metrics', 'cache.stores.broken_metrics' => [
        'driver' => 'database', 'table' => 'missing_metrics_table', 'connection' => config('database.default'),
    ]]);
    expect($metrics->record(10.0))->toBeFalse()->and($metrics->snapshot()['p95_ms'])->toBeNull()
        ->and($metrics->snapshot()['collection_status'])->toBe('unavailable');
});

test('resolver histogram rejects non finite measurements and handles overflow honestly', function () {
    $metrics = app(PublicResolverMetrics::class);
    expect($metrics->record(INF))->toBeFalse()->and($metrics->record(NAN))->toBeFalse()
        ->and($metrics->record(-1.0))->toBeFalse()->and($metrics->record(12345.6))->toBeTrue()
        ->and($metrics->snapshot()['p95_ms'])->toBe(12346)->and($metrics->snapshot()['sample_count'])->toBe(1);
});
