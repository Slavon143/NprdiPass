<?php

namespace App\Actions\Billing;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Exceptions\Billing\BillingProviderException;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\Contracts\StripeBillingProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateBillingPortalSessionAction
{
    public function __construct(
        private readonly StripeBillingProvider $provider,
        private readonly CompanyAuthorizer $authorizer,
    ) {}

    public function execute(User $actor, Company $company): string
    {
        Gate::forUser($actor)->authorize(CompanyPermission::BillingManage->value, $company);

        // No automatic transaction retry: a remote session cannot be rolled back.
        return DB::transaction(function () use ($actor, $company): string {
            $this->authorizer->authorize($actor, $company, CompanyPermission::BillingManage);
            $company = Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $subscription = CompanySubscription::query()->forCompany($company)->sharedLock()->first();
            if (! $subscription instanceof CompanySubscription) {
                throw new BillingProviderException('Create a subscription before opening the billing portal.');
            }

            return $this->provider
                ->createPortalSession($company, $subscription, route('settings.billing.index'))
                ->url;
        });
    }
}
