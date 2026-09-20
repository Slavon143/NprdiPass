<?php

namespace App\Actions\Billing;

use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Services\Billing\BillingProjectionService;
use App\Services\Billing\Contracts\StripeBillingProvider;

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
            ->with('company')
            ->chunkById(100, function ($subscriptions) use (&$checked, &$updated, &$skipped): void {
                foreach ($subscriptions as $subscription) {
                    $checked++;
                    $company = $subscription->company;

                    if (! $company instanceof Company) {
                        $skipped++;

                        continue;
                    }

                    $projection = $this->provider->retrieveSubscription((string) $subscription->stripe_subscription_id);
                    $fresh = $this->projection->upsert($company, $projection);
                    $fresh->forceFill(['last_reconciled_at' => now()])->save();
                    $updated++;
                }
            });

        return compact('checked', 'updated', 'skipped');
    }
}
