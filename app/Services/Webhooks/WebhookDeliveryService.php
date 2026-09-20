<?php

namespace App\Services\Webhooks;

use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Company;
use App\Models\Webhooks\WebhookDelivery;
use App\Models\Webhooks\WebhookEndpoint;
use Illuminate\Support\Collection;

class WebhookDeliveryService
{
    public function __construct(
        private readonly WebhookPayloadFactory $payloadFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, WebhookDelivery>
     */
    public function enqueue(
        Company $company,
        WebhookEventType $eventType,
        array $data,
        ?string $aggregateType = null,
        ?string $aggregateUuid = null,
    ): Collection {
        $eventId = (string) str()->uuid();
        $payload = $this->payloadFactory->make($company, $eventType, $data, $eventId);
        $deliveries = collect();

        WebhookEndpoint::query()
            ->forCompany($company)
            ->where('status', WebhookEndpointStatus::Active->value)
            ->whereJsonContains('events', $eventType->value)
            ->each(function (WebhookEndpoint $endpoint) use ($company, $eventType, $aggregateType, $aggregateUuid, $payload, $eventId, $deliveries): void {
                $delivery = WebhookDelivery::query()->create([
                    'company_id' => $company->getKey(),
                    'webhook_endpoint_id' => $endpoint->getKey(),
                    'event_type' => $eventType,
                    'aggregate_type' => $aggregateType,
                    'aggregate_uuid' => $aggregateUuid,
                    'payload' => $payload,
                    'status' => WebhookDeliveryStatus::Pending,
                    'idempotency_key' => hash('sha256', $endpoint->uuid.'|'.$eventId),
                ]);

                DeliverWebhookJob::dispatch($delivery->id);
                $deliveries->push($delivery);
            });

        return $deliveries;
    }
}
