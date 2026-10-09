<?php

namespace App\Actions\Billing;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\CompanyPermission;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Contracts\StripeBillingProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelPendingBillingCheckoutAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CompanyAuthorizer $authorizer,
        private readonly StripeBillingProvider $provider,
    ) {}

    public function execute(User $actor, Company $company): CompanySubscription
    {
        Gate::forUser($actor)->authorize(CompanyPermission::BillingManage->value, $company);

        return DB::transaction(function () use ($actor, $company): CompanySubscription {
            $this->authorizer->authorize($actor, $company, CompanyPermission::BillingManage);

            $subscription = CompanySubscription::query()
                ->forCompany($company)
                ->lockForUpdate()
                ->first();

            if (! $subscription instanceof CompanySubscription || $subscription->status !== BillingSubscriptionStatus::Pending) {
                throw ValidationException::withMessages([
                    'subscription' => 'There is no pending checkout to cancel.',
                ]);
            }

            if ($subscription->stripe_customer_id !== null || $subscription->stripe_subscription_id !== null) {
                throw ValidationException::withMessages([
                    'subscription' => 'Stripe has already accepted this checkout. Wait for the subscription webhook or manage the subscription in Stripe.',
                ]);
            }

            $metadata = $subscription->metadata ?? [];
            if (($metadata['pending_checkout']['creation_uncertain'] ?? false) === true) {
                throw ValidationException::withMessages([
                    'subscription' => 'Checkout creation is not confirmed. Reconcile Stripe before cancelling this checkout.',
                ]);
            }
            $sessionId = $metadata['pending_checkout']['session_id'] ?? null;
            if ((! is_string($sessionId) || $sessionId === '') && (string) config('billing.provider') !== 'fake') {
                throw ValidationException::withMessages([
                    'subscription' => 'Checkout session is not known. Reconcile Stripe before cancelling this checkout.',
                ]);
            }
            if (is_string($sessionId) && $sessionId !== '') {
                $this->provider->expireCheckoutSession($sessionId);
            }
            $previousState = is_array($metadata['pending_checkout']['previous_state'] ?? null)
                ? $metadata['pending_checkout']['previous_state']
                : null;

            if ($previousState !== null) {
                $subscription->forceFill($previousState);
            } elseif ($company->commercial_model_version === 1) {
                // A lost/absent pre-checkout snapshot cannot mint a new local trial.
                $subscription->forceFill(['status' => BillingSubscriptionStatus::Canceled, 'entitlements' => []]);
            } else {
                $planKey = $this->inferPlanKey($subscription);
                /** @var array<string, mixed> $plan */
                $plan = (array) config("billing.plans.{$planKey}", []);
                $trialDays = max(1, (int) ($plan['trial_days'] ?? 14));

                $subscription->forceFill([
                    'plan_key' => $planKey,
                    'status' => BillingSubscriptionStatus::Trialing,
                    'stripe_customer_id' => null,
                    'stripe_subscription_id' => null,
                    'stripe_price_id' => null,
                    'entitlements' => $subscription->entitlements ?? ($plan['entitlements'] ?? []),
                    'trial_ends_at' => $subscription->trial_ends_at ?? now()->addDays($trialDays),
                    'current_period_start' => $subscription->current_period_start ?? now(),
                    'current_period_end' => $subscription->current_period_end ?? now()->addDays($trialDays),
                    'canceled_at' => null,
                    'grace_ends_at' => null,
                ]);
            }

            $restoredMetadata = $subscription->metadata ?? [];
            unset($restoredMetadata['pending_checkout']);
            $subscription->forceFill(['metadata' => $restoredMetadata])->save();

            $this->auditLogger->logTenant(
                $company,
                AuditEvent::BillingSubscriptionUpdated,
                $actor,
                $subscription,
                [
                    'subscription_status' => $subscription->status->value,
                    'plan_key' => $subscription->plan_key,
                    'pending_checkout_cancelled' => true,
                ],
            );

            return $subscription;
        });
    }

    private function inferPlanKey(CompanySubscription $subscription): string
    {
        $entitlements = $subscription->entitlements ?? [];

        foreach ((array) config('billing.plans', []) as $key => $plan) {
            if (($plan['entitlements'] ?? []) === $entitlements) {
                return (string) $key;
            }
        }

        return (string) config('billing.default_plan', 'pilot');
    }
}
