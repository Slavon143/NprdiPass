<?php

namespace App\Jobs\Webhooks;

use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Exceptions\Webhooks\RetryableWebhookDeliveryException;
use App\Models\Webhooks\WebhookDelivery;
use App\Models\Webhooks\WebhookDeliveryAttempt;
use App\Models\Webhooks\WebhookEndpoint;
use App\Services\Webhooks\WebhookCommercialAccess;
use App\Services\Webhooks\WebhookSignature;
use App\Services\Webhooks\WebhookUrlSafety;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function __construct(
        public readonly int $deliveryId,
        public readonly ?int $companyId = null,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookSignature $signature, ?WebhookUrlSafety $urlSafety = null): void
    {
        $urlSafety ??= app(WebhookUrlSafety::class);
        $claimed = $this->claimAttempt();
        if ($claimed === null) {
            return;
        }
        [$delivery, $attempt] = $claimed;
        $endpoint = $delivery->endpoint;

        $payload = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $url = $endpoint->url;
        $attemptNumber = $attempt->attempt;

        try {
            $connectionOptions = $urlSafety->connectionOptions($url);
        } catch (Throwable $exception) {
            $this->markAttemptException($delivery, $attempt, $exception);
            throw $exception;
        }

        $response = null;
        try {
            for ($redirects = 0; $redirects <= 3; $redirects++) {
                $response = Http::timeout((int) config('webhooks.delivery_timeout_seconds', 10))
                    ->setHandler(new CurlHandler)
                    ->withOptions($connectionOptions)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'NordiPass-Signature' => $signature->sign($payload, $endpoint->secret),
                        'NordiPass-Event-ID' => (string) ($delivery->payload['id'] ?? $delivery->uuid),
                        'Idempotency-Key' => $delivery->idempotency_key,
                        'User-Agent' => 'NordiPass-Webhooks/1.0',
                    ])
                    ->withBody($payload, 'application/json')
                    ->post($url);

                if (! $response->redirect()) {
                    break;
                }

                $location = $response->header('Location');
                if ($location === '' || $redirects === 3) {
                    break;
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
                try {
                    $connectionOptions = $urlSafety->connectionOptions($url);
                } catch (Throwable $exception) {
                    $this->markAttemptException($delivery, $attempt, $exception);
                    throw $exception;
                }
            }
        } catch (ConnectionException) {
            $backoff = $this->backoff();
            $attempt->forceFill([
                'status' => 'failed', 'error_code' => 'connection_failure',
                'error_message' => 'Webhook connection failed.', 'finished_at' => now(),
            ])->save();
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Pending,
                'next_attempt_at' => now()->addSeconds($backoff[$attemptNumber - 1] ?? $backoff[array_key_last($backoff)]),
                'failure_code' => 'connection_failure', 'failure_message' => 'Webhook connection failed.',
            ])->save();
            throw new RetryableWebhookDeliveryException('Webhook connection failed; delivery will be retried.', 0);
        }

        $responseStatus = $response->status();
        $excerpt = str($response->body())->limit((int) config('webhooks.response_excerpt_bytes', 2000), '')->toString();
        $attempt->forceFill([
            'status' => $response->successful() ? 'delivered' : 'failed',
            'response_status' => $responseStatus,
            'response_body_excerpt' => $excerpt,
            'finished_at' => now(),
        ])->save();

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Delivered,
                'delivered_at' => now(), 'response_status' => $responseStatus,
                'response_body_excerpt' => $excerpt, 'next_attempt_at' => null,
                'failure_code' => null, 'failure_message' => null,
            ])->save();

            return;
        }

        $retryable = $response->status() === 429 || $response->serverError();
        $backoff = $this->backoff();
        $delivery->forceFill([
            'status' => $retryable ? WebhookDeliveryStatus::Pending : WebhookDeliveryStatus::Failed,
            'response_status' => $responseStatus, 'response_body_excerpt' => $excerpt,
            'next_attempt_at' => $retryable ? now()->addSeconds($backoff[$attemptNumber - 1] ?? $backoff[array_key_last($backoff)]) : null,
            'failure_code' => $retryable ? 'retryable_http_failure' : 'non_retryable_http_failure',
            'failure_message' => 'Webhook endpoint returned an unsuccessful response.',
        ])->save();

        if ($retryable) {
            throw new RetryableWebhookDeliveryException('Webhook delivery will be retried.', $responseStatus);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = WebhookDelivery::query()->when($this->companyId !== null,
            fn ($query) => $query->where('company_id', $this->companyId))->find($this->deliveryId);
        if (! $delivery instanceof WebhookDelivery || $delivery->status === WebhookDeliveryStatus::Delivered) {
            return;
        }

        $delivery->attemptsLog()->where('status', 'started')->latest('id')->first()?->forceFill([
            'status' => 'failed', 'error_code' => 'delivery_exception',
            'error_message' => 'Webhook delivery exhausted its retries.', 'finished_at' => now(),
        ])->save();

        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::DeadLetter,
            'next_attempt_at' => null,
            'failure_code' => 'dead_letter',
            'failure_message' => 'Webhook delivery exhausted its retries.',
        ])->save();
    }

    /** @return array{WebhookDelivery, WebhookDeliveryAttempt}|null */
    private function claimAttempt(): ?array
    {
        // Legacy queued jobs may omit the tenant scalar. Resolve only its identity;
        // admission and the delivery itself are then read under current locks.
        $companyId = $this->companyId ?? WebhookDelivery::query()->whereKey($this->deliveryId)->value('company_id');
        if ($companyId === null) {
            return null;
        }

        return DB::transaction(function () use ($companyId): ?array {
            $allowed = app(WebhookCommercialAccess::class)->allowsDelivery((int) $companyId);
            $delivery = WebhookDelivery::query()->where('company_id', $companyId)
                ->with('endpoint')->lockForUpdate()->find($this->deliveryId);
            if ($delivery === null || $delivery->status !== WebhookDeliveryStatus::Pending
                || $delivery->next_attempt_at?->isFuture()) {
                return null;
            }
            if (! $allowed) {
                $delivery->forceFill([
                    'next_attempt_at' => now()->addMinutes(10),
                    'failure_code' => 'commercial_access_paused',
                    'failure_message' => 'Webhook delivery is paused until access is restored.',
                ])->save();

                return null;
            }
            $endpoint = $delivery->endpoint;
            if (! $endpoint instanceof WebhookEndpoint || $endpoint->status !== WebhookEndpointStatus::Active
                || (int) $endpoint->company_id !== (int) $delivery->company_id) {
                return null;
            }
            $delivery->attemptsLog()->where('status', 'started')->update([
                'status' => 'failed', 'error_code' => 'worker_interrupted',
                'error_message' => 'The previous delivery attempt did not finish.', 'finished_at' => now(),
            ]);
            if ($delivery->attempts >= $this->tries) {
                $delivery->forceFill(['status' => WebhookDeliveryStatus::DeadLetter, 'next_attempt_at' => null,
                    'failure_code' => 'dead_letter', 'failure_message' => 'Webhook delivery exhausted its retries.'])->save();

                return null;
            }
            $attempt = WebhookDeliveryAttempt::create([
                'webhook_delivery_id' => $delivery->id, 'attempt' => $delivery->attempts + 1,
                'status' => 'started', 'started_at' => now(),
            ]);
            $leaseSeconds = max($this->timeout, 4 * (int) config('webhooks.delivery_timeout_seconds', 10)) + 60;
            $delivery->forceFill(['attempts' => $attempt->attempt, 'last_attempt_at' => now(),
                'next_attempt_at' => now()->addSeconds($leaseSeconds)])->save();

            return [$delivery, $attempt];
        });
    }

    private function markAttemptException(WebhookDelivery $delivery, WebhookDeliveryAttempt $attempt, Throwable $exception): void
    {
        $attempt->forceFill([
            'status' => 'failed', 'error_code' => 'delivery_exception',
            'error_message' => $exception->getMessage(), 'finished_at' => now(),
        ])->save();
        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Failed,
            'next_attempt_at' => null, 'failure_code' => 'delivery_exception',
            'failure_message' => $exception->getMessage(),
        ])->save();
    }
}
