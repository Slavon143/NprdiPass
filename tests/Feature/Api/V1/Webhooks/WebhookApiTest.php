<?php

use App\Enums\ApiTokenAbility;
use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Models\Webhooks\WebhookDelivery;
use App\Models\Webhooks\WebhookEndpoint;
use App\Services\Webhooks\WebhookDeliveryService;
use App\Services\Webhooks\WebhookSignature;
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
        'https://example.test/webhooks' => Http::response('accepted', 202),
    ]);

    $company = Company::factory()->create();
    $endpoint = WebhookEndpoint::query()->create([
        'company_id' => $company->getKey(),
        'url' => 'https://example.test/webhooks',
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

        return $request->url() === 'https://example.test/webhooks'
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
