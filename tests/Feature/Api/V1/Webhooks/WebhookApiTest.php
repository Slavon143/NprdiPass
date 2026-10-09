<?php

use App\Enums\ApiTokenAbility;
use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Exceptions\Webhooks\RetryableWebhookDeliveryException;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Models\Webhooks\WebhookDelivery;
use App\Models\Webhooks\WebhookEndpoint;
use App\Services\Webhooks\WebhookDeliveryService;
use App\Services\Webhooks\WebhookSignature;
use App\Services\Webhooks\WebhookUrlSafety;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('webhook endpoint api requires the dedicated token ability', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);

    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::CompanyRead->value]);

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/webhooks/endpoints')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'token_ability_missing');
});

test('webhook endpoints are tenant scoped and do not expose secrets', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $foreignCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);

    WebhookEndpoint::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'url' => 'https://example.test/foreign',
        'secret' => 'foreign-secret-value',
        'events' => [WebhookEventType::PassportPublished->value],
        'status' => WebhookEndpointStatus::Active,
    ]);

    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::WebhooksManage->value]);

    $created = $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/webhooks/endpoints', [
            'url' => 'https://example.test/webhooks',
            'secret' => 'local-secret-value',
            'events' => [WebhookEventType::PassportPublished->value],
        ])
        ->assertCreated()
        ->assertJsonPath('data.url', 'https://example.test/webhooks')
        ->assertJsonMissingPath('data.secret');

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/webhooks/endpoints')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $created->json('data.uuid'))
        ->assertDontSee('foreign-secret-value')
        ->assertDontSee('local-secret-value')
        ->assertDontSee('https://example.test/foreign');
});

test('webhook delivery service queues tenant endpoints and sender signs payloads', function () {
    Queue::fake();
    Http::fake([
        'https://example.com/webhooks' => Http::response('accepted', 202),
    ]);

    $company = Company::factory()->create();
    $endpoint = WebhookEndpoint::query()->create([
        'company_id' => $company->getKey(),
        'url' => 'https://example.com/webhooks',
        'secret' => 'delivery-secret-value',
        'events' => [WebhookEventType::PassportPublished->value],
        'status' => WebhookEndpointStatus::Active,
    ]);

    $deliveries = app(WebhookDeliveryService::class)->enqueue(
        $company,
        WebhookEventType::PassportPublished,
        ['passport_uuid' => '00000000-0000-4000-8000-000000000001'],
        'product_passport',
        '00000000-0000-4000-8000-000000000001',
    );

    expect($deliveries)->toHaveCount(1);

    $delivery = WebhookDelivery::query()->firstOrFail();

    Queue::assertPushed(DeliverWebhookJob::class, fn (DeliverWebhookJob $job): bool => $job->deliveryId === $delivery->id);

    (new DeliverWebhookJob($delivery->id))->handle(app(WebhookSignature::class));

    $delivery->refresh();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(202)
        ->and($delivery->webhook_endpoint_id)->toBe($endpoint->getKey());

    Http::assertSent(function ($request): bool {
        $header = $request->header('NordiPass-Signature')[0] ?? '';
        $eventId = $request->header('NordiPass-Event-ID')[0] ?? '';
        $idempotencyKey = $request->header('Idempotency-Key')[0] ?? '';

        return $request->url() === 'https://example.com/webhooks'
            && str_starts_with($header, 't=')
            && str_contains($header, ',v1=')
            && $eventId !== ''
            && $idempotencyKey !== ''
            && $request['type'] === WebhookEventType::PassportPublished->value;
    });
});

test('webhook delivery log is tenant scoped', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $foreignCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $company, 'user_id' => $user]);

    $endpoint = WebhookEndpoint::query()->create([
        'company_id' => $company->getKey(),
        'url' => 'https://example.test/local',
        'secret' => 'local-delivery-secret',
        'events' => [WebhookEventType::PassportPublished->value],
        'status' => WebhookEndpointStatus::Active,
    ]);
    $foreignEndpoint = WebhookEndpoint::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'url' => 'https://example.test/foreign',
        'secret' => 'foreign-delivery-secret',
        'events' => [WebhookEventType::PassportPublished->value],
        'status' => WebhookEndpointStatus::Active,
    ]);

    WebhookDelivery::query()->create([
        'company_id' => $company->getKey(),
        'webhook_endpoint_id' => $endpoint->getKey(),
        'event_type' => WebhookEventType::PassportPublished,
        'payload' => ['id' => 'local-event', 'type' => WebhookEventType::PassportPublished->value],
        'status' => WebhookDeliveryStatus::Pending,
        'idempotency_key' => 'local-key',
    ]);
    WebhookDelivery::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'webhook_endpoint_id' => $foreignEndpoint->getKey(),
        'event_type' => WebhookEventType::PassportPublished,
        'payload' => ['id' => 'foreign-event', 'type' => WebhookEventType::PassportPublished->value],
        'status' => WebhookDeliveryStatus::Pending,
        'idempotency_key' => 'foreign-key',
    ]);

    $token = issueCompanyApiToken($user, $company, [ApiTokenAbility::WebhooksManage->value]);

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/webhooks/deliveries')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.endpoint_uuid', $endpoint->uuid)
        ->assertDontSee('foreign-event')
        ->assertDontSee($foreignEndpoint->uuid);
});

test('webhook URL safety rejects private destinations before delivery', function () {
    expect(fn () => app(WebhookUrlSafety::class)->assertSafe('https://127.0.0.1/internal', false))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(WebhookUrlSafety::class)->assertSafe('https://[::1]/internal', false))
        ->toThrow(InvalidArgumentException::class);
});

test('webhook HTTP failures remain pending and are retried by the queue', function () {
    Http::fake(['https://example.com/retry' => Http::response('busy', 500)]);

    $company = Company::factory()->create();
    $endpoint = WebhookEndpoint::query()->create([
        'company_id' => $company->getKey(), 'url' => 'https://example.com/retry',
        'secret' => 'delivery-secret-value', 'events' => [WebhookEventType::PassportPublished->value],
        'status' => WebhookEndpointStatus::Active,
    ]);
    $delivery = WebhookDelivery::query()->create([
        'company_id' => $company->getKey(), 'webhook_endpoint_id' => $endpoint->getKey(),
        'event_type' => WebhookEventType::PassportPublished,
        'payload' => ['id' => 'retry-event', 'type' => WebhookEventType::PassportPublished->value],
        'status' => WebhookDeliveryStatus::Pending, 'idempotency_key' => 'retry-key',
    ]);

    expect(fn () => (new DeliverWebhookJob($delivery->id))->handle(app(WebhookSignature::class)))
        ->toThrow(RetryableWebhookDeliveryException::class);

    $delivery->refresh();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->attemptsLog)->toHaveCount(1)
        ->and($delivery->attemptsLog->first()->response_status)->toBe(500)
        ->and($delivery->next_attempt_at)->not->toBeNull();

    (new DeliverWebhookJob($delivery->id))->failed(new RuntimeException('retry budget exhausted'));
    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::DeadLetter);
});

test('webhook redirects are validated before following them', function () {
    Http::fake(['https://example.com/redirect' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);

    $company = Company::factory()->create();
    $endpoint = WebhookEndpoint::query()->create([
        'company_id' => $company->getKey(), 'url' => 'https://example.com/redirect',
        'secret' => 'delivery-secret-value', 'events' => [WebhookEventType::PassportPublished->value],
        'status' => WebhookEndpointStatus::Active,
    ]);
    $delivery = WebhookDelivery::query()->create([
        'company_id' => $company->getKey(), 'webhook_endpoint_id' => $endpoint->getKey(),
        'event_type' => WebhookEventType::PassportPublished,
        'payload' => ['id' => 'redirect-event', 'type' => WebhookEventType::PassportPublished->value],
        'status' => WebhookDeliveryStatus::Pending, 'idempotency_key' => 'redirect-key',
    ]);

    expect(fn () => (new DeliverWebhookJob($delivery->id))->handle(app(WebhookSignature::class)))
        ->toThrow(InvalidArgumentException::class);

    expect($delivery->refresh()->attemptsLog()->latest('id')->first()?->status)->toBe('failed');
});

test('webhook network failures finish each attempt and keep delivery retryable without storing raw exceptions', function () {
    Http::fake(['https://93.184.216.34/events' => Http::failedConnection('sensitive transport details')]);
    $company = Company::factory()->create();
    $endpoint = WebhookEndpoint::create([
        'company_id' => $company->id, 'url' => 'https://93.184.216.34/events', 'secret' => 'fixture-secret',
        'events' => ['passport.published'], 'status' => 'active',
    ]);
    $delivery = WebhookDelivery::create([
        'company_id' => $company->id, 'webhook_endpoint_id' => $endpoint->id, 'event_type' => 'passport.published',
        'payload' => ['id' => 'connection-test'], 'status' => 'pending', 'idempotency_key' => 'connection-test',
    ]);
    for ($number = 1; $number <= 2; $number++) {
        if ($number > 1) {
            $this->travel(61)->seconds();
        }
        expect(fn () => (new DeliverWebhookJob($delivery->id, $company->id))->handle(app(WebhookSignature::class)))
            ->toThrow(RetryableWebhookDeliveryException::class);
        $delivery->refresh();
        expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
            ->and($delivery->attempts)->toBe($number)
            ->and($delivery->next_attempt_at)->not->toBeNull()
            ->and($delivery->attemptsLog()->where('status', 'started')->count())->toBe(0)
            ->and($delivery->failure_message)->toBe('Webhook connection failed.');
    }
    (new DeliverWebhookJob($delivery->id, $company->id))->failed(new RuntimeException('sensitive transport details'));
    expect($delivery->refresh()->failure_message)->toBe('Webhook delivery exhausted its retries.');
});

test('outbox recovery selects only stale due tenant-safe pending deliveries', function () {
    Queue::fake();
    $company = Company::factory()->create();
    $endpoint = WebhookEndpoint::create([
        'company_id' => $company->id, 'url' => 'https://93.184.216.34/events', 'secret' => 'fixture-secret',
        'events' => ['passport.published'], 'status' => 'active',
    ]);
    $create = fn (array $attributes) => WebhookDelivery::query()->forceCreate($attributes + [
        'company_id' => $company->id, 'webhook_endpoint_id' => $endpoint->id, 'event_type' => 'passport.published',
        'payload' => ['id' => (string) str()->uuid()], 'status' => 'pending', 'idempotency_key' => (string) str()->uuid(),
        'created_at' => now()->subMinutes(10),
    ]);
    $stale = $create([]);
    $due = $create(['next_attempt_at' => now()->subSecond()]);
    $create(['next_attempt_at' => now()->addMinute()]);
    $create(['created_at' => now()]);
    $create(['status' => 'delivered']);
    $create(['status' => 'failed']);
    $create(['company_id' => Company::factory()->create()->id]);
    $this->artisan('webhooks:recover')->assertSuccessful();
    Queue::assertPushed(DeliverWebhookJob::class, 2);
    Queue::assertPushed(DeliverWebhookJob::class, fn ($job) => $job->deliveryId === $stale->id && $job->companyId === $company->id);
    Queue::assertPushed(DeliverWebhookJob::class, fn ($job) => $job->deliveryId === $due->id && $job->companyId === $company->id);
});

test('a claimed webhook attempt excludes duplicate jobs and an interrupted attempt can be recovered', function () {
    $company = Company::factory()->create();
    $endpoint = WebhookEndpoint::create([
        'company_id' => $company->id, 'url' => 'https://93.184.216.34/events', 'secret' => 'fixture-secret',
        'events' => ['passport.published'], 'status' => 'active',
    ]);
    $delivery = WebhookDelivery::create([
        'company_id' => $company->id, 'webhook_endpoint_id' => $endpoint->id, 'event_type' => 'passport.published',
        'payload' => ['id' => 'lease-test'], 'status' => 'pending', 'idempotency_key' => 'lease-test',
    ]);
    $job = new DeliverWebhookJob($delivery->id, $company->id);
    $interrupt = true;
    Http::fake(function () use ($job, &$interrupt) {
        if (! $interrupt) {
            return Http::response('', 204);
        }
        $job->handle(app(WebhookSignature::class));
        throw new RuntimeException('Injected worker interruption');
    });
    expect(fn () => $job->handle(app(WebhookSignature::class)))->toThrow(RuntimeException::class, 'Injected worker interruption');
    expect($delivery->refresh()->attempts)->toBe(1);
    $job->handle(app(WebhookSignature::class));
    expect($delivery->refresh()->attempts)->toBe(1);
    $this->travel(101)->seconds();
    $interrupt = false;
    $job->handle(app(WebhookSignature::class));
    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->attempts)->toBe(2)
        ->and($delivery->attemptsLog()->where('attempt', 1)->first()->error_code)->toBe('worker_interrupted');
    $job->handle(app(WebhookSignature::class));
    Http::assertSentCount(1);

    $delivery->forceFill(['status' => 'pending', 'attempts' => 5, 'next_attempt_at' => now()->subSecond()])->save();
    $last = $delivery->attemptsLog()->create(['attempt' => 5, 'status' => 'started', 'started_at' => now()->subMinutes(2)]);
    $job->handle(app(WebhookSignature::class));
    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::DeadLetter)
        ->and($delivery->attempts)->toBe(5)
        ->and($last->refresh()->status)->toBe('failed')->and($last->finished_at)->not->toBeNull();
    Http::assertSentCount(1);
});
