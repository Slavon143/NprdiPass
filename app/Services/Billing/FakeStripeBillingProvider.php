<?php

namespace App\Services\Billing;

use App\Data\Billing\StripeCheckoutSession;
use App\Data\Billing\StripePortalSession;
use App\Data\Billing\StripeSubscriptionProjection;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Services\Billing\Contracts\StripeBillingProvider;

class FakeStripeBillingProvider implements StripeBillingProvider
{
    public function createCheckoutSession(Company $company, string $planKey, string $successUrl, string $cancelUrl): StripeCheckoutSession
    {
        return new StripeCheckoutSession(
            id: 'cs_test_'.$company->uuid,
            url: route('settings.billing.index', ['checkout' => 'fake', 'plan' => $planKey]),
        );
    }

    public function createPortalSession(Company $company, CompanySubscription $subscription, string $returnUrl): StripePortalSession
    {
        return new StripePortalSession(
            id: 'bps_test_'.$company->uuid,
            url: $returnUrl,
        );
    }

    public function retrieveSubscription(string $stripeSubscriptionId): StripeSubscriptionProjection
    {
        return new StripeSubscriptionProjection(
            stripeCustomerId: 'cus_fake',
            stripeSubscriptionId: $stripeSubscriptionId,
            stripePriceId: (string) config('billing.plans.pilot.stripe_price_id'),
            planKey: (string) config('billing.default_plan', 'pilot'),
            status: BillingSubscriptionStatus::Active,
            currentPeriodStart: now()->toImmutable(),
            currentPeriodEnd: now()->toImmutable()->addMonth(),
        );
    }

    public function listSubscriptions(string $stripeCustomerId): array
    {
        return [];
    }
}
