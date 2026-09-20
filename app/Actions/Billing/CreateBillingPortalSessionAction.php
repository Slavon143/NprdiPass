<?php

namespace App\Actions\Billing;

use App\Enums\CompanyPermission;
use App\Exceptions\Billing\BillingProviderException;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Contracts\StripeBillingProvider;
use Illuminate\Support\Facades\Gate;

class CreateBillingPortalSessionAction
{
    public function __construct(private readonly StripeBillingProvider $provider) {}

    public function execute(User $actor, Company $company): string
    {
        Gate::forUser($actor)->authorize(CompanyPermission::BillingManage->value, $company);

        $subscription = CompanySubscription::query()->forCompany($company)->first();
        if (! $subscription instanceof CompanySubscription) {
            throw new BillingProviderException('Create a subscription before opening the billing portal.');
        }

        return $this->provider
            ->createPortalSession($company, $subscription, route('settings.billing.index'))
            ->url;
    }
}
