<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Webhooks\WebhookEndpoint;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class WebhookEndpointController extends Controller
{
    public function index(TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $endpoints = WebhookEndpoint::query()
            ->forCompany($currentCompany->require())
            ->latest()
            ->paginate(50);

        return $response->paginated(
            $endpoints->getCollection()->map(fn (WebhookEndpoint $endpoint): array => $this->endpointData($endpoint))->all(),
            $endpoints,
        );
    }

    public function store(Request $request, TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $company = $currentCompany->require();
        $validated = $request->validate([
            'url' => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'secret' => ['required', 'string', 'min:16', 'max:255'],
            'events' => ['required', 'array', 'min:1', 'max:20'],
            'events.*' => ['required', 'distinct', new Enum(WebhookEventType::class)],
        ]);

        $endpoint = WebhookEndpoint::query()->create([
            'company_id' => $company->getKey(),
            'created_by' => $request->user()?->getAuthIdentifier(),
            'url' => $validated['url'],
            'secret' => $validated['secret'],
            'events' => array_values($validated['events']),
            'status' => WebhookEndpointStatus::Active,
        ]);

        return $response->created($this->endpointData($endpoint));
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $endpoint,
    ): JsonResponse {
        $model = $this->resolveEndpoint($currentCompany, $endpoint);
        $validated = $request->validate([
            'url' => ['sometimes', 'required', 'url', 'starts_with:https://', 'max:2048'],
            'secret' => ['sometimes', 'required', 'string', 'min:16', 'max:255'],
            'events' => ['sometimes', 'required', 'array', 'min:1', 'max:20'],
            'events.*' => ['required_with:events', 'distinct', new Enum(WebhookEventType::class)],
            'status' => ['sometimes', 'required', Rule::enum(WebhookEndpointStatus::class)],
        ]);

        $model->fill($validated);
        $model->save();

        return $response->success($this->endpointData($model));
    }

    public function disable(
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $endpoint,
    ): JsonResponse {
        $model = $this->resolveEndpoint($currentCompany, $endpoint);
        $model->forceFill(['status' => WebhookEndpointStatus::Disabled])->save();

        return $response->success($this->endpointData($model));
    }

    private function resolveEndpoint(TokenCurrentCompany $currentCompany, string $uuid): WebhookEndpoint
    {
        return WebhookEndpoint::query()
            ->forCompany($currentCompany->require())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function endpointData(WebhookEndpoint $endpoint): array
    {
        return [
            'uuid' => $endpoint->uuid,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'status' => $endpoint->status->value,
            'created_at' => $endpoint->created_at?->toISOString(),
            'updated_at' => $endpoint->updated_at?->toISOString(),
        ];
    }
}
