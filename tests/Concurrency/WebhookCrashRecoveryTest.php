<?php

use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Company;
use App\Models\Webhooks\WebhookDelivery;
use App\Models\Webhooks\WebhookEndpoint;
use App\Services\Webhooks\WebhookSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

test('a real worker crash preserves webhook leases event identity and receiver deduplication', function (string $mode, int $exitCode) {
    expect(DB::connection()->getDriverName())->toBe('mysql')->and(DB::connection()->getDatabaseName())->toEndWith('_testing');
    $this->artisan('migrate:fresh')->assertSuccessful();
    config(['queue.default' => 'database']);
    $company = Company::factory()->create();
    $endpoint = WebhookEndpoint::create(['company_id' => $company->id, 'url' => 'https://93.184.216.34/events',
        'secret' => 'crash-drill-fixture', 'events' => ['passport.published'], 'status' => 'active']);
    $delivery = WebhookDelivery::create(['company_id' => $company->id, 'webhook_endpoint_id' => $endpoint->id,
        'event_type' => 'passport.published', 'payload' => ['id' => 'crash-drill-event'], 'status' => 'pending',
        'idempotency_key' => 'crash-drill-key']);
    DeliverWebhookJob::dispatch($delivery->id, $company->id);
    $marker = tempnam(sys_get_temp_dir(), 'np-receiver-drill-');
    file_put_contents($marker, json_encode(['received' => [], 'applied' => []], JSON_THROW_ON_ERROR));
    $connection = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
        'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
        'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null',
        'APP_CONFIG_CACHE' => config('app.config_cache', storage_path('framework/crash-drill-config.php'))];
    try {
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/webhook-crash-worker.php'), $marker, $mode], base_path(), $environment);
        $worker->setTimeout(30)->run();
        expect($worker->getExitCode())->toBe($exitCode, $worker->getErrorOutput())
            ->and(DB::table('jobs')->whereNotNull('reserved_at')->count())->toBe(1)
            ->and($delivery->refresh()->attempts)->toBe(1);
        Queue::fake();
        $this->artisan('webhooks:recover')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->travel(101)->seconds();
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($marker) {
            $key = $request->header('Idempotency-Key')[0];
            $state = json_decode(file_get_contents($marker), true);
            $state['received'][] = $key;
            $state['applied'] = array_values(array_unique([...$state['applied'], $key]));
            file_put_contents($marker, json_encode($state, JSON_THROW_ON_ERROR));

            return Http::response('', 204);
        });
        $this->artisan('webhooks:recover')->assertSuccessful();
        if ($mode === 'after-receiver') {
            Queue::assertPushed(DeliverWebhookJob::class, 1);
            expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Pending)
                ->and($delivery->attemptsLog()->sole()->status)->toBe('started');
        } else {
            Queue::assertNothingPushed();
            expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Delivered);
        }
        (new DeliverWebhookJob($delivery->id, $company->id))->handle(app(WebhookSignature::class));
        $state = json_decode(file_get_contents($marker), true);
        expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Delivered)
            ->and($state['applied'])->toBe(['crash-drill-key'])
            ->and($state['received'])->toHaveCount($mode === 'after-receiver' ? 2 : 1)
            ->and($delivery->attempts)->toBe($mode === 'after-receiver' ? 2 : 1);
        if ($mode === 'after-receiver') {
            expect($delivery->attemptsLog()->where('attempt', 1)->sole()->error_code)->toBe('worker_interrupted');
        }
    } finally {
        unlink($marker);
    }
})->with([['after-receiver', 91], ['after-success', 92]]);
