<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Actions\Webhooks\CreateWebhookEndpoint;
use App\Actions\Webhooks\DisableWebhookEndpoint;
use App\Actions\Webhooks\UpdateWebhookEndpoint;
use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Enums\Webhooks\WebhookEventType;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Webhooks\WebhookEndpoint;
use App\Services\Webhooks\WebhookUrlSafety;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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

    public function store(Request $request, TokenCurrentCompany $currentCompany, ApiResponse $response, WebhookUrlSafety $urlSafety, CreateWebhookEndpoint $action): JsonResponse
    {
        $company = $currentCompany->require();
        $validated = $request->validate([
            'url' => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'secret' => ['required', 'string', 'min:16', 'max:255'],
            'events' => ['required', 'array', 'min:1', 'max:20'],
            'events.*' => ['required', 'distinct', new Enum(WebhookEventType::class)],
        ]);
        try {
            $urlSafety->assertSafe($validated['url'], false);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }

        $endpoint = $action->execute($this->actor($request), $company, [
            'url' => $validated['url'], 'secret' => $validated['secret'], 'events' => array_values($validated['events']),
        ]);

        return $response->created($this->endpointData($endpoint));
    }

    public function update(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        WebhookUrlSafety $urlSafety,
        string $endpoint,
        UpdateWebhookEndpoint $action,
    ): JsonResponse {
        $model = $this->resolveEndpoint($currentCompany, $endpoint);
        $validated = $request->validate([
            'url' => ['sometimes', 'required', 'url', 'starts_with:https://', 'max:2048'],
            'secret' => ['sometimes', 'required', 'string', 'min:16', 'max:255'],
            'events' => ['sometimes', 'required', 'array', 'min:1', 'max:20'],
            'events.*' => ['required_with:events', 'distinct', new Enum(WebhookEventType::class)],
            'status' => ['sometimes', 'required', Rule::enum(WebhookEndpointStatus::class)],
        ]);
        if (isset($validated['url'])) {
            try {
                $urlSafety->assertSafe($validated['url'], false);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['url' => $exception->getMessage()]);
            }
        }

        $model = $action->execute($this->actor($request), $currentCompany->require(), $model->uuid, $validated);

        return $response->success($this->endpointData($model));
    }

    public function disable(
        Request $request,
        TokenCurrentCompany $currentCompany,
        ApiResponse $response,
        string $endpoint,
        DisableWebhookEndpoint $action,
    ): JsonResponse {
        $model = $this->resolveEndpoint($currentCompany, $endpoint);
        $model = $action->execute($this->actor($request), $currentCompany->require(), $model->uuid);

        return $response->success($this->endpointData($model));
    }

    private function resolveEndpoint(TokenCurrentCompany $currentCompany, string $uuid): WebhookEndpoint
    {
        return WebhookEndpoint::query()
            ->forCompany($currentCompany->require())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
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
