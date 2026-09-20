<?php

namespace App\Actions\Billing;

use App\Audit\AuditLogger;
use App\Data\Billing\StripeSubscriptionProjection;
use App\Enums\AuditEvent;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\Billing\StripeWebhookEventStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Billing\StripeWebhookEvent;
use App\Models\Company;
use App\Services\Billing\BillingProjectionService;
use App\Services\Billing\HttpStripeBillingProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessStripeWebhookAction
{
    public function __construct(
        private readonly BillingProjectionService $projection,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array<string, mixed> $payload */
    public function execute(array $payload): StripeWebhookEvent
    {
        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['type'] ?? '');
        if ($eventId === '' || $eventType === '') {
            throw ValidationException::withMessages(['payload' => 'Stripe webhook is missing an event id or type.']);
        }

        // Persist the inbox row outside the projection transaction so failed events
        // remain observable instead of rolling back with the projection.
        $event = StripeWebhookEvent::query()->firstOrCreate(
            ['stripe_event_id' => $eventId],
            [
                'type' => $eventType,
                'livemode' => (bool) ($payload['livemode'] ?? false),
                'status' => StripeWebhookEventStatus::Received,
                'payload' => $payload,
                'received_at' => now(),
            ],
        );

        if ($event->status === StripeWebhookEventStatus::Processed) {
            return $event;
        }

        $event->increment('attempts');

        try {
            DB::transaction(function () use ($event, $eventId, $payload, $eventType): void {
                // Guard against concurrent duplicate delivery of the same event id.
                $locked = StripeWebhookEvent::query()
                    ->where('stripe_event_id', $eventId)
                    ->lockForUpdate()
                    ->first();
                if ($locked?->status === StripeWebhookEventStatus::Processed) {
                    return;
                }

                $this->applyEvent($payload, $eventType);

                $event->forceFill([
                    'status' => StripeWebhookEventStatus::Processed,
                    'processed_at' => now(),
                    'error_message' => null,
                    'failed_at' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => StripeWebhookEventStatus::Failed,
                'error_message' => $exception->getMessage(),
                'failed_at' => now(),
            ])->save();

            throw $exception;
        }

        return $event->fresh() ?? $event;
    }

    /** @param array<string, mixed> $payload */
    private function applyEvent(array $payload, string $eventType): void
    {
        if ($eventType === 'checkout.session.completed') {
            $this->applyCheckoutCompleted($payload);

            return;
        }

        if ($eventType === 'checkout.session.expired') {
            $this->applyCheckoutExpired($payload);

            return;
        }

        if (! str_starts_with($eventType, 'customer.subscription.')) {
            return;
        }

        $object = $payload['data']['object'] ?? null;
        if (! is_array($object)) {
            throw ValidationException::withMessages(['payload' => 'Stripe subscription webhook is missing its object payload.']);
        }

        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $companyUuid = (string) ($metadata['company_uuid'] ?? '');
        $company = Company::query()->where('uuid', $companyUuid)->first();
        if (! $company instanceof Company) {
            throw ValidationException::withMessages(['company' => 'Stripe webhook company could not be resolved.']);
        }

        $projection = app(HttpStripeBillingProvider::class)->projectionFromSubscription($object);
        if ($eventType === 'customer.subscription.deleted') {
            $projection = new StripeSubscriptionProjection(
                stripeCustomerId: $projection->stripeCustomerId,
                stripeSubscriptionId: $projection->stripeSubscriptionId,
                stripePriceId: $projection->stripePriceId,
                planKey: $projection->planKey,
                status: BillingSubscriptionStatus::Canceled,
                trialEndsAt: $projection->trialEndsAt,
                currentPeriodStart: $projection->currentPeriodStart,
                currentPeriodEnd: $projection->currentPeriodEnd,
                canceledAt: $projection->canceledAt ?? now()->toImmutable(),
                metadata: $projection->metadata,
            );
        }

        $subscription = $this->projection->upsert($company, $projection);
        $this->auditLogger->logTenant(
            $company,
            AuditEvent::BillingSubscriptionUpdated,
            null,
            $subscription,
            [
                'stripe_event_type' => $eventType,
                'subscription_status' => $subscription->status->value,
                'plan_key' => $subscription->plan_key,
            ],
        );
    }

    /**
     * `checkout.session.expired` releases a pending claim created at checkout start when the
     * customer never completed the session, so the company can start a new checkout.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyCheckoutExpired(array $payload): void
    {
        $object = $payload['data']['object'] ?? null;
        if (! is_array($object)) {
            throw ValidationException::withMessages(['payload' => 'Stripe checkout webhook is missing its object payload.']);
        }

        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $companyUuid = (string) ($metadata['company_uuid'] ?? $object['client_reference_id'] ?? '');
        $company = Company::query()->where('uuid', $companyUuid)->first();
        if (! $company instanceof Company) {
            throw ValidationException::withMessages(['company' => 'Stripe checkout webhook company could not be resolved.']);
        }

        $subscription = CompanySubscription::query()->forCompany($company)->first();
        if ($subscription === null || $subscription->status !== BillingSubscriptionStatus::Pending) {
            return;
        }

        $releasedPlanKey = (string) $subscription->getAttribute('plan_key');

        // A pending row that carries a Stripe customer came from a previously canceled
        // subscription: restore it to canceled instead of dropping the customer reference.
        if ($subscription->getAttribute('stripe_customer_id') !== null) {
            $subscription->forceFill([
                'status' => BillingSubscriptionStatus::Canceled,
                'stripe_subscription_id' => null,
                'stripe_price_id' => null,
            ])->save();
        } else {
            // The checkout never produced a subscription: release the slot entirely.
            $subscription->delete();
        }

        $this->auditLogger->logTenant(
            $company,
            AuditEvent::BillingSubscriptionUpdated,
            null,
            null,
            [
                'stripe_event_type' => 'checkout.session.expired',
                'subscription_status' => 'released',
                'plan_key' => $releasedPlanKey,
            ],
        );
    }

    /**
     * `checkout.session.completed` is not the source of truth for the subscription state:
     * it records the selected plan and Stripe customer so the UI can show a pending state,
     * while `customer.subscription.*` events confirm the actual subscription status.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyCheckoutCompleted(array $payload): void
    {
        $object = $payload['data']['object'] ?? null;
        if (! is_array($object)) {
            throw ValidationException::withMessages(['payload' => 'Stripe checkout webhook is missing its object payload.']);
        }

        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $companyUuid = (string) ($metadata['company_uuid'] ?? $object['client_reference_id'] ?? '');
        $company = Company::query()->where('uuid', $companyUuid)->first();
        if (! $company instanceof Company) {
            throw ValidationException::withMessages(['company' => 'Stripe checkout webhook company could not be resolved.']);
        }

        $planKey = (string) ($metadata['plan_key'] ?? config('billing.default_plan', 'pilot'));
        if (! array_key_exists($planKey, (array) config('billing.plans'))) {
            throw ValidationException::withMessages(['plan' => 'Stripe checkout webhook plan could not be resolved.']);
        }

        // A confirmed subscription webhook must never be downgraded by a late checkout event.
        $existing = CompanySubscription::query()->forCompany($company)->first();
        if ($existing !== null && $existing->status->isNonTerminal() && $existing->stripe_subscription_id !== null) {
            return;
        }

        // Never null out identifiers already known from a previous checkout while waiting
        // for the subscription webhook to confirm the new subscription.
        $stripeSubscriptionId = isset($object['subscription']) && is_string($object['subscription'])
            ? $object['subscription']
            : $existing?->stripe_subscription_id;
        $stripeCustomerId = (string) ($object['customer'] ?? '');
        if ($stripeCustomerId === '') {
            $stripeCustomerId = (string) ($existing->stripe_customer_id ?? '');
        }

        $projection = new StripeSubscriptionProjection(
            stripeCustomerId: $stripeCustomerId,
            stripeSubscriptionId: $stripeSubscriptionId,
            stripePriceId: (string) config("billing.plans.{$planKey}.stripe_price_id"),
            planKey: $planKey,
            status: BillingSubscriptionStatus::Pending,
            metadata: $metadata,
        );

        $subscription = $this->projection->upsert($company, $projection);
        $this->auditLogger->logTenant(
            $company,
            AuditEvent::BillingSubscriptionUpdated,
            null,
            $subscription,
            [
                'stripe_event_type' => 'checkout.session.completed',
                'subscription_status' => $subscription->status->value,
                'plan_key' => $subscription->plan_key,
            ],
        );
    }
}
