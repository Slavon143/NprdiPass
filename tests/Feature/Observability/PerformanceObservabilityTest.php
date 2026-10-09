<?php

use App\Enums\PlatformRole;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Observability\ObservabilitySnapshotService;
use App\Services\Observability\RequestTelemetry;
use App\Services\Passports\Public\PublicPassportResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function r317PlatformUser(): User
{
    test()->seed(RolePermissionSeeder::class);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(PlatformRole::SuperAdmin->value);

    return $user;
}

test('observability snapshot exposes SLO checks without personal data', function () {
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'SensitiveJob', 'email' => 'person@example.test'], JSON_THROW_ON_ERROR),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinutes(10)->timestamp,
        'created_at' => now()->subMinutes(10)->timestamp,
    ]);

    config(['observability.slo.queue_lag_seconds' => 60]);

    $snapshot = app(ObservabilitySnapshotService::class)->snapshot();
    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

    expect($snapshot['status'])->toBe('degraded')
        ->and($snapshot['checks']['queue_lag'])->toBe('breached')
        ->and($snapshot['queue']['pending_jobs'])->toBe(1)
        ->and($encoded)->not->toContain('person@example.test')
        ->and($encoded)->not->toContain('SensitiveJob');
});

test('unmeasured resolver latency and unsupported queue backend are unknown', function () {
    config(['queue.default' => 'redis', 'observability.slo.storage_free_percent' => 0]);
    $snapshot = app(ObservabilitySnapshotService::class)->snapshot();
    expect($snapshot['public_resolver']['p95_ms'])->toBeNull()
        ->and($snapshot['checks']['public_resolver_latency'])->toBe('unknown')
        ->and($snapshot['queue']['pending_jobs'])->toBeNull()
        ->and($snapshot['checks']['queue_lag'])->toBe('unknown')
        ->and($snapshot['status'])->toBe('unknown');
});

test('scheduled and reserved jobs do not create queue lag and unsupported failure storage is unknown', function () {
    config(['queue.default' => 'audit_queue', 'queue.connections.audit_queue' => [
        'driver' => 'database', 'connection' => config('database.default'), 'table' => 'jobs',
    ], 'queue.failed.driver' => 'null']);
    foreach ([['reserved_at' => null, 'available_at' => now()->addHour()->timestamp],
        ['reserved_at' => now()->timestamp, 'available_at' => now()->subHour()->timestamp]] as $job) {
        DB::table('jobs')->insert($job + ['queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'created_at' => now()->subDay()->timestamp]);
    }
    $snapshot = app(ObservabilitySnapshotService::class)->snapshot();
    expect($snapshot['queue']['pending_jobs'])->toBe(2)
        ->and($snapshot['queue']['oldest_pending_seconds'])->toBe(0)
        ->and($snapshot['checks']['queue_lag'])->toBe('ok')
        ->and($snapshot['queue']['failed_jobs'])->toBeNull()
        ->and($snapshot['checks']['failed_jobs'])->toBe('unknown');
});

test('telemetry collects actual queries and resets at request boundaries', function () {
    $telemetry = app(RequestTelemetry::class);
    $telemetry->start();
    DB::select('SELECT 1');
    expect($telemetry->summary()['query_count'])->toBe(1);
    $telemetry->stop();
    DB::select('SELECT 2');
    expect($telemetry->summary()['query_count'])->toBe(1);
    $telemetry->start();
    expect($telemetry->summary()['query_count'])->toBe(0);
});

test('public resolver timings come from execution and reset across requests including misses', function () {
    $passport = ProductPassport::factory()->published()->create();
    $version = ProductPassportVersion::factory()->published()->create([
        'company_id' => $passport->company_id, 'passport_id' => $passport->id, 'payload' => [],
    ]);
    $passport->forceFill(['current_published_version_id' => $version->id])->save();
    $telemetry = app(RequestTelemetry::class);
    $resolver = app(PublicPassportResolver::class);
    $telemetry->start();
    $started = hrtime(true);
    $resolver->resolve($passport->public_id);
    $elapsed = (hrtime(true) - $started) / 1_000_000;
    expect($telemetry->summary()['public_resolver_ms'])->toBeFloat()->toBeGreaterThan(0)
        ->toBeLessThanOrEqual($elapsed + 1);
    $telemetry->start();
    expect($telemetry->summary()['public_resolver_ms'])->toBeNull();
    expect(fn () => $resolver->resolve((string) str()->uuid()))->toThrow(NotFoundHttpException::class);
    expect($telemetry->summary()['public_resolver_ms'])->toBeFloat()->toBeGreaterThan(0);
    $telemetry->stop();
});

test('observability command emits machine readable JSON', function () {
    $exitCode = Artisan::call('observability:snapshot', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['status', 'queue', 'public_resolver']);
});

test('platform observability dashboard is restricted to platform operators', function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get(route('platform.operations.observability'))
        ->assertForbidden();

    $this->actingAs(r317PlatformUser())
        ->get(route('platform.operations.observability'))
        ->assertOk()
        ->assertSee('Observability')
        ->assertSee('SLO snapshot');
});

test('request telemetry log is PII safe and records query profile', function () {
    $user = User::factory()->create([
        'email' => 'private.person@example.test',
        'email_verified_at' => now(),
    ]);

    Log::shouldReceive('withContext')->zeroOrMoreTimes();
    Log::shouldReceive('withoutContext')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('info')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'observability.request'
                && ($context['route'] ?? null) === 'profile.edit'
                && array_key_exists('query_count', $context)
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'private.person@example.test');
        })
        ->once();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk();
});

test('large catalog observability baseline stays within a bounded query budget', function () {
    $company = Company::factory()->create();

    $rows = collect(range(1, 1000))->map(fn (int $index): array => [
        'uuid' => (string) Str::uuid(),
        'company_id' => $company->getKey(),
        'name' => 'Baseline Product '.$index,
        'slug' => 'baseline-product-'.$index,
        'slug_normalized' => 'baseline-product-'.$index,
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($rows->chunk(200) as $chunk) {
        DB::table('products')->insert($chunk->all());
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $snapshot = app(ObservabilitySnapshotService::class)->snapshot();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($snapshot['catalog']['products'])->toBeGreaterThanOrEqual(1000)
        ->and($snapshot['catalog']['large_catalog_companies'])->toBe(1)
        ->and(count($queries))->toBeLessThanOrEqual(10);
});
