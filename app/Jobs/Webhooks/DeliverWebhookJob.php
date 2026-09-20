<?php

namespace App\Jobs\Webhooks;

use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Models\Webhooks\WebhookDelivery;
use App\Models\Webhooks\WebhookEndpoint;
use App\Services\Webhooks\WebhookSignature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $deliveryId,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookSignature $signature): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);

        if (! $delivery instanceof WebhookDelivery || $delivery->status === WebhookDeliveryStatus::Delivered) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if (! $endpoint instanceof WebhookEndpoint || $endpoint->status !== WebhookEndpointStatus::Active) {
            return;
        }

        $payload = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);

        if (! is_string($payload)) {
            return;
        }

        $response = Http::timeout((int) config('webhooks.delivery_timeout_seconds', 10))
            ->withHeaders([
                'Content-Type' => 'application/json',
                'NordiPass-Signature' => $signature->sign($payload, $endpoint->secret),
                'NordiPass-Event-ID' => (string) ($delivery->payload['id'] ?? $delivery->uuid),
                'Idempotency-Key' => $delivery->idempotency_key,
                'User-Agent' => 'NordiPass-Webhooks/1.0',
            ])
            ->withBody($payload, 'application/json')
            ->post($endpoint->url);

        $status = $response->successful()
            ? WebhookDeliveryStatus::Delivered
            : WebhookDeliveryStatus::Failed;

        $delivery->forceFill([
            'status' => $status,
            'attempts' => $delivery->attempts + 1,
            'last_attempt_at' => now(),
            'delivered_at' => $status === WebhookDeliveryStatus::Delivered ? now() : null,
            'response_status' => $response->status(),
            'response_body_excerpt' => str($response->body())->limit((int) config('webhooks.response_excerpt_bytes', 2000), '')->toString(),
            'next_attempt_at' => $status === WebhookDeliveryStatus::Failed ? now()->addMinutes(5) : null,
        ])->save();
    }
}
