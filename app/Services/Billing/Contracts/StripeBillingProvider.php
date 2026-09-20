<?php

namespace App\Services\Billing\Contracts;

use App\Data\Billing\StripeCheckoutSession;
use App\Data\Billing\StripePortalSession;
use App\Data\Billing\StripeSubscriptionProjection;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;

interface StripeBillingProvider
{
    public function createCheckoutSession(Company $company, string $planKey, string $successUrl, string $cancelUrl): StripeCheckoutSession;

    public function createPortalSession(Company $company, CompanySubscription $subscription, string $returnUrl): StripePortalSession;

    public function retrieveSubscription(string $stripeSubscriptionId): StripeSubscriptionProjection;

    /**
     * List all subscriptions of a Stripe customer regardless of status.
     *
     * @return list<StripeSubscriptionProjection>
     */
    public function listSubscriptions(string $stripeCustomerId): array;
}
