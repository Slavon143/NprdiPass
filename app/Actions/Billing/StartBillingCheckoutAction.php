<?php

namespace App\Actions\Billing;

use App\Authorization\CompanyAuthorizer;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\CompanyPermission;
use App\Exceptions\Billing\BillingProviderException;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\CommercialPlanCatalog;
use App\Services\Billing\Contracts\StripeBillingProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StartBillingCheckoutAction
{
    public function __construct(
        private readonly StripeBillingProvider $provider,
        private readonly CompanyAuthorizer $authorizer,
    ) {}

    public function execute(User $actor, Company $company, string $planKey, string $interval = 'month'): string
    {
        Gate::forUser($actor)->authorize(CompanyPermission::BillingManage->value, $company);

        app(CommercialPlanCatalog::class)->checkoutPlan($planKey, $interval);

        $subscription = CompanySubscription::query()->forCompany($company)->first();
        $canStartTrialCheckout = $subscription?->status === BillingSubscriptionStatus::Trialing
            && $subscription->stripe_customer_id === null
            && $subscription->stripe_subscription_id === null;
        if ($subscription !== null && $subscription->isNonTerminal() && ! $canStartTrialCheckout) {
            throw $this->nonTerminalError($subscription->status->value);
        }

        // The fake provider is a local simulation without real Stripe state or races.
        $creationAttempted = false;
        if ((string) config('billing.provider') === 'fake') {
            return $this->createSession($actor, $company, $planKey, null, $creationAttempted, $interval);
        }

        // Serialize checkout starts per company and claim the single subscription slot
        // with a pending row. The unique company_id constraint on company_subscriptions
        // plus the company row lock makes a concurrent double-click impossible.
        $claimId = (string) Str::uuid();
        [$claimed, $previousState, $wasCreated] = DB::transaction(function () use ($actor, $company, $planKey, $claimId, $interval): array {
            $this->authorizer->authorize($actor, $company, CompanyPermission::BillingManage);

            $existing = CompanySubscription::query()->forCompany($company)->lockForUpdate()->first();
            $canStartTrialCheckout = $existing?->status === BillingSubscriptionStatus::Trialing
                && $existing->stripe_customer_id === null
                && $existing->stripe_subscription_id === null;
            if ($existing !== null && $existing->isNonTerminal() && ! $canStartTrialCheckout) {
                throw $this->nonTerminalError($existing->status->value);
            }

            $previousState = $existing === null
                ? null
                : $existing->only([
                    'stripe_customer_id',
                    'stripe_subscription_id',
                    'stripe_price_id',
                    'plan_key',
                    'status',
                    'entitlements',
                    'trial_ends_at',
                    'current_period_start',
                    'current_period_end',
                    'canceled_at',
                    'grace_ends_at',
                    'metadata',
                ]);

            $subscription = $existing ?? new CompanySubscription;
            $metadata = $existing instanceof CompanySubscription ? ($existing->metadata ?? []) : [];
            // Completion belongs to the prior attempt, retained in previous_state.
            unset($metadata['checkout_session_id']);
            $metadata['pending_checkout'] = [
                'claim_id' => $claimId,
                'plan_key' => $planKey,
                'billing_interval' => $interval,
                'started_at' => now()->toISOString(),
                'previous_state' => $previousState,
                // Until a response is recorded, cancellation cannot prove that
                // Stripe did not create a remotely completable session.
                'creation_uncertain' => true,
            ];

            $subscription->forceFill([
                'company_id' => $company->getKey(),
                'plan_key' => $planKey,
                'status' => BillingSubscriptionStatus::Pending,
                // The new checkout will produce a new subscription; clear identifiers so a
                // late checkout.session.completed webhook can record it.
                'stripe_subscription_id' => null,
                'stripe_price_id' => null,
                'metadata' => $metadata,
            ])->save();

            return [$subscription, $previousState, $existing === null];
        });

        try {
            // Guard against drift: a non-terminal subscription may exist in Stripe even when
            // the local projection is missing or terminal.
            $customerId = (string) ($previousState['stripe_customer_id'] ?? '');
            if ($customerId !== '') {
                foreach ($this->provider->listSubscriptions($customerId) as $remote) {
                    if ($remote->status->isNonTerminal()) {
                        throw $this->nonTerminalError($remote->status->value);
                    }
                }
            }

            return $this->createSession($actor, $company, $planKey, $claimId, $creationAttempted, $interval);
        } catch (Throwable $exception) {
            // A lost response/commit acknowledgement cannot undo a Stripe call.
            // Keep the durable uncertain claim until it is reconciled.
            if ($creationAttempted && (! $exception instanceof BillingProviderException || $exception->retryable)) {
                throw $exception;
            }

            DB::transaction(function () use ($company, $claimed, $claimId, $previousState, $wasCreated): void {
                Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
                $current = CompanySubscription::query()->forCompany($company)->whereKey($claimed->getKey())->lockForUpdate()->first();
                if ($current?->status !== BillingSubscriptionStatus::Pending
                    || ($current->metadata['pending_checkout']['claim_id'] ?? null) !== $claimId
                    || $current->getAttribute('stripe_subscription_id') !== null) {
                    return;
                }
                if ($wasCreated) {
                    $current->delete();

                    return;
                }

                if ($previousState !== null) {
                    $current->forceFill($previousState)->save();
                }
            });

            throw $exception;
        }
    }

    private function createSession(User $actor, Company $company, string $planKey, ?string $claimId, bool &$creationAttempted, string $interval): string
    {
        return DB::transaction(function () use ($actor, $company, $planKey, $claimId, &$creationAttempted, $interval): string {
            $this->authorizer->authorize($actor, $company, CompanyPermission::BillingManage);
            $company = Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $subscription = $claimId === null ? null : CompanySubscription::query()->forCompany($company)->lockForUpdate()->first();
            if ($claimId !== null && ($subscription?->status !== BillingSubscriptionStatus::Pending
                || ($subscription->metadata['pending_checkout']['claim_id'] ?? null) !== $claimId
                || ($subscription->metadata['pending_checkout']['billing_interval'] ?? null) !== $interval)) {
                throw $this->nonTerminalError($subscription?->status->value ?? 'changed');
            }

            $creationAttempted = true;
            $successUrl = url((string) config('billing.success_path'));
            $cancelUrl = url((string) config('billing.cancel_path'));
            $session = $claimId === null
                ? $this->provider->createCheckoutSession($company, $planKey, $successUrl, $cancelUrl, $interval)
                : $this->provider->createClaimedCheckoutSession($company, $planKey, $successUrl, $cancelUrl, 'nordipass-checkout-'.$claimId, $interval);
            if ($session->id === '' || $session->url === '') {
                throw BillingProviderException::retryable('Stripe checkout session response is incomplete.');
            }
            if ($subscription !== null) {
                $metadata = $subscription->metadata ?? [];
                $metadata['pending_checkout']['session_id'] = $session->id;
                unset($metadata['pending_checkout']['creation_uncertain']);
                $subscription->forceFill(['metadata' => $metadata])->save();
            }

            return $session->url;
        });
    }

    private function nonTerminalError(string $status): ValidationException
    {
        return ValidationException::withMessages([
            'plan' => "This company already has a subscription that is pending or active ({$status}).",
        ]);
    }
}
