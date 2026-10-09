<?php

namespace App\Actions\Billing;

use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Services\Billing\BillingProjectionService;
use App\Services\Billing\Contracts\StripeBillingProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReconcileBillingProjectionAction
{
    public function __construct(
        private readonly StripeBillingProvider $provider,
        private readonly BillingProjectionService $projection,
    ) {}

    /** @return array{checked: int, updated: int, skipped: int} */
    public function execute(): array
    {
        $checked = 0;
        $updated = 0;
        $skipped = 0;

        CompanySubscription::query()
            ->whereNotNull('stripe_subscription_id')
            ->chunkById(100, function ($subscriptions) use (&$checked, &$updated, &$skipped): void {
                foreach ($subscriptions as $subscription) {
                    $checked++;
                    $changed = DB::transaction(function () use ($subscription): bool {
                        $company = Company::query()->whereKey($subscription->company_id)->lockForUpdate()->first();
                        if (! $company instanceof Company) {
                            return false;
                        }
                        $current = CompanySubscription::query()->forCompany($company)->lockForUpdate()->first();
                        // A scan is only a work candidate. Never retrieve an old
                        // subscription after a newer claim replaced its binding.
                        if (! $current instanceof CompanySubscription || $current->getKey() !== $subscription->getKey()
                            || $current->stripe_subscription_id === null
                            || $current->stripe_subscription_id !== $subscription->stripe_subscription_id) {
                            return false;
                        }
                        $remote = $this->provider->retrieveSubscription((string) $current->stripe_subscription_id);
                        $remoteCompany = $remote->metadata['company_uuid'] ?? null;
                        if ($remote->stripeSubscriptionId !== $current->stripe_subscription_id
                            || ($current->stripe_customer_id !== null && $remote->stripeCustomerId !== $current->stripe_customer_id)
                            || ($remoteCompany !== null && $remoteCompany !== $company->uuid)
                            || ($company->commercial_model_version === 1 && $remoteCompany !== $company->uuid)) {
                            throw ValidationException::withMessages(['subscription' => 'Stripe reconciliation identity does not match this company subscription.']);
                        }
                        $fresh = $this->projection->upsert($company, $remote);
                        $fresh->forceFill(['last_reconciled_at' => now()])->save();

                        return true;
                    });
                    $changed ? $updated++ : $skipped++;
                }
            });

        return compact('checked', 'updated', 'skipped');
    }
}
