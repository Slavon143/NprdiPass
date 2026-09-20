<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Webhooks\WebhookDelivery;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebhookDeliveryController extends Controller
{
    public function index(Request $request, TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'required', Rule::enum(WebhookDeliveryStatus::class)],
            'event_type' => ['sometimes', 'required', Rule::enum(WebhookEventType::class)],
            'per_page' => ['sometimes', 'integer', 'in:25,50,100'],
        ]);

        $deliveries = WebhookDelivery::query()
            ->forCompany($currentCompany->require())
            ->with('endpoint')
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(isset($validated['event_type']), fn ($query) => $query->where('event_type', $validated['event_type']))
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 50));

        return $response->paginated(
            $deliveries->getCollection()->map(fn (WebhookDelivery $delivery): array => $this->deliveryData($delivery))->all(),
            $deliveries,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryData(WebhookDelivery $delivery): array
    {
        return [
            'uuid' => $delivery->uuid,
            'endpoint_uuid' => $delivery->endpoint?->uuid,
            'event_type' => $delivery->event_type->value,
            'aggregate_type' => $delivery->aggregate_type,
            'aggregate_uuid' => $delivery->aggregate_uuid,
            'status' => $delivery->status->value,
            'attempts' => $delivery->attempts,
            'response_status' => $delivery->response_status,
            'last_attempt_at' => $delivery->last_attempt_at?->toISOString(),
            'delivered_at' => $delivery->delivered_at?->toISOString(),
            'created_at' => $delivery->created_at?->toISOString(),
        ];
    }
}
