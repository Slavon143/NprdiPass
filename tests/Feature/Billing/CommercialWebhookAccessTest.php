<?php

use App\Actions\Companies\CreateCompanyForOwner;
use App\Enums\ApiTokenAbility;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\CompanyStatus;
use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\User;
use App\Models\Webhooks\WebhookDelivery;
use App\Models\Webhooks\WebhookEndpoint;
use App\Services\Webhooks\WebhookDeliveryService;
use App\Services\Webhooks\WebhookSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['billing.commercial_enabled' => true, 'billing.plans' => config('billing.commercial_plans')]);
    Http::preventStrayRequests();
    Queue::fake();
    $this->actor = User::factory()->create();
    $this->company = app(CreateCompanyForOwner::class)->execute($this->actor, 'Webhook commercial boundaries');
    $this->endpoint = WebhookEndpoint::create([
        'company_id' => $this->company->id, 'url' => 'https://93.184.216.34/events',
        'secret' => 'local-webhook-fixture', 'events' => [WebhookEventType::PassportPublished->value], 'status' => 'active',
    ]);
});

test('a retained webhook manage token cannot configure endpoints on the local trial', function () {
    $token = issueCompanyApiToken($this->actor, $this->company, [ApiTokenAbility::WebhooksManage->value]);
    $this->withToken($token->plainTextToken)->postJson('/api/v1/webhooks/endpoints', [
        'url' => 'https://example.test/events', 'secret' => 'local-fixture',
        'events' => [WebhookEventType::PassportPublished->value],
    ])->assertUnprocessable();
    expect(WebhookEndpoint::query()->where('company_id', $this->company->id)->count())->toBe(1);
});

test('trial and expired tenants do not create outgoing deliveries from stale company objects', function () {
    $staleCompany = clone $this->company;
    $staleCompany->commercial_model_version = null;
    $enqueue = fn () => app(WebhookDeliveryService::class)->enqueue($staleCompany, WebhookEventType::PassportPublished, ['passport_uuid' => 'local-passport']);
    expect($enqueue())->toHaveCount(0);
    $subscription = $this->company->subscription()->firstOrFail();
    $entitlements = $subscription->entitlements;
    $entitlements['webhooks.enabled'] = true;
    $subscription->update(['entitlements' => $entitlements, 'trial_ends_at' => now()->subSecond()]);
    expect($enqueue())->toHaveCount(0)->and(WebhookDelivery::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('delivery pauses without spending attempts and resumes with the same event identity', function () {
    $subscription = $this->company->subscription()->firstOrFail();
    $entitlements = $subscription->entitlements;
    $entitlements['webhooks.enabled'] = true;
    $subscription->update(['entitlements' => $entitlements]);
    $delivery = app(WebhookDeliveryService::class)->enqueue($this->company, WebhookEventType::PassportPublished, ['passport_uuid' => 'local-passport'])->sole();
    $identity = $delivery->idempotency_key;
    $subscription->update(['trial_ends_at' => now()->subSecond()]);
    (new DeliverWebhookJob($delivery->id, $this->company->id))->handle(app(WebhookSignature::class));
    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->failure_code)->toBe('commercial_access_paused')
        ->and($delivery->attemptsLog()->count())->toBe(0);
    Http::assertNothingSent();
    $subscription->update(['status' => BillingSubscriptionStatus::Active]);
    $this->travel(11)->minutes();
    Http::fake(['https://93.184.216.34/events' => Http::response('', 204)]);
    (new DeliverWebhookJob($delivery->id, $this->company->id))->handle(app(WebhookSignature::class));
    expect($delivery->refresh()->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->attempts)->toBe(1)->and($delivery->idempotency_key)->toBe($identity);
    Http::assertSentCount(1);
});

test('a disabled feature or suspended tenant pauses an existing legacy scalar job', function () {
    $delivery = WebhookDelivery::create([
        'company_id' => $this->company->id, 'webhook_endpoint_id' => $this->endpoint->id,
        'event_type' => WebhookEventType::PassportPublished, 'payload' => ['id' => 'retained-event'],
        'status' => WebhookDeliveryStatus::Pending, 'idempotency_key' => 'retained-event-key',
    ]);
    (new DeliverWebhookJob($delivery->id))->handle(app(WebhookSignature::class));
    expect($delivery->refresh()->attempts)->toBe(0)->and($delivery->failure_code)->toBe('commercial_access_paused');
    $this->company->forceFill(['commercial_model_version' => null, 'status' => CompanyStatus::Suspended])->save();
    $this->travel(11)->minutes();
    (new DeliverWebhookJob($delivery->id))->handle(app(WebhookSignature::class));
    expect($delivery->refresh()->attempts)->toBe(0);
    Http::assertNothingSent();
});

test('an explicit foreign tenant scalar cannot pause or send another tenant delivery', function () {
    $foreign = app(CreateCompanyForOwner::class)->execute(User::factory()->create(), 'Foreign webhook tenant');
    $delivery = WebhookDelivery::create([
        'company_id' => $this->company->id, 'webhook_endpoint_id' => $this->endpoint->id,
        'event_type' => WebhookEventType::PassportPublished, 'payload' => ['id' => 'scoped-event'],
        'status' => WebhookDeliveryStatus::Pending, 'idempotency_key' => 'scoped-event-key',
    ]);
    (new DeliverWebhookJob($delivery->id, $foreign->id))->handle(app(WebhookSignature::class));
    expect($delivery->refresh()->failure_code)->toBeNull()->and($delivery->attempts)->toBe(0);
    Http::assertNothingSent();
});
