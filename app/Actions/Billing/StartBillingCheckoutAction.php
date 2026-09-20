<?php

namespace App\Actions\Billing;

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\CompanyPermission;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Contracts\StripeBillingProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class StartBillingCheckoutAction
{
    public function __construct(private readonly StripeBillingProvider $provider) {}

    public function execute(User $actor, Company $company, string $planKey): string
    {
        Gate::forUser($actor)->authorize(CompanyPermission::BillingManage->value, $company);

        if (! array_key_exists($planKey, (array) config('billing.plans'))) {
            throw ValidationException::withMessages(['plan' => 'The selected billing plan is not available.']);
        }

        $subscription = CompanySubscription::query()->forCompany($company)->first();
        if ($subscription !== null && $subscription->isNonTerminal()) {
            throw $this->nonTerminalError($subscription->status->value);
        }

        // The fake provider is a local simulation without real Stripe state or races.
        if ((string) config('billing.provider') === 'fake') {
            return $this->createSession($company, $planKey);
        }

        // Serialize checkout starts per company and claim the single subscription slot
        // with a pending row. The unique company_id constraint on company_subscriptions
        // plus the company row lock makes a concurrent double-click impossible.
        [$claimed, $previousState, $wasCreated] = DB::transaction(function () use ($company, $planKey): array {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->first();

            $existing = CompanySubscription::query()->forCompany($company)->first();
            if ($existing !== null && $existing->isNonTerminal()) {
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
            $subscription->forceFill([
                'company_id' => $company->getKey(),
                'plan_key' => $planKey,
                'status' => BillingSubscriptionStatus::Pending,
                // The new checkout will produce a new subscription; clear identifiers so a
                // late checkout.session.completed webhook can record it.
                'stripe_subscription_id' => null,
                'stripe_price_id' => null,
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

            return $this->createSession($company, $planKey);
        } catch (Throwable $exception) {
            // Release the claimed slot so the company is not stuck in pending.
            DB::transaction(function () use ($claimed, $previousState, $wasCreated): void {
                if ($wasCreated) {
                    $claimed->delete();

                    return;
                }

                if ($previousState !== null) {
                    $claimed->forceFill($previousState)->save();
                }
            });

            throw $exception;
        }
    }

    private function createSession(Company $company, string $planKey): string
    {
        return $this->provider->createCheckoutSession(
            $company,
            $planKey,
            url((string) config('billing.success_path')),
            url((string) config('billing.cancel_path')),
        )->url;
    }

    private function nonTerminalError(string $status): ValidationException
    {
        return ValidationException::withMessages([
            'plan' => "This company already has a subscription that is pending or active ({$status}).",
        ]);
    }
}
