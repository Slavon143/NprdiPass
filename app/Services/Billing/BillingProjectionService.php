<?php

namespace App\Services\Billing;

use App\Data\Billing\StripeSubscriptionProjection;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;

class BillingProjectionService
{
    public function upsert(Company $company, StripeSubscriptionProjection $projection): CompanySubscription
    {
        /** @var array<string, mixed> $plan */
        $plan = (array) config("billing.plans.{$projection->planKey}", []);

        $existing = CompanySubscription::query()->forCompany($company)->first();
        $existingGraceEnd = $existing instanceof CompanySubscription ? $existing->grace_ends_at : null;
        $graceEndsAt = match ($projection->status) {
            BillingSubscriptionStatus::PastDue => $existingGraceEnd ?? now()->addDays(7),
            BillingSubscriptionStatus::Active, BillingSubscriptionStatus::Trialing => null,
            default => $existingGraceEnd,
        };

        return CompanySubscription::query()->updateOrCreate(
            ['company_id' => $company->getKey()],
            [
                'stripe_customer_id' => $projection->stripeCustomerId,
                'stripe_subscription_id' => $projection->stripeSubscriptionId,
                'stripe_price_id' => $projection->stripePriceId,
                'plan_key' => $projection->planKey,
                'status' => $projection->status,
                'entitlements' => $plan['entitlements'] ?? [],
                'trial_ends_at' => $projection->trialEndsAt,
                'current_period_start' => $projection->currentPeriodStart,
                'current_period_end' => $projection->currentPeriodEnd,
                'canceled_at' => $projection->canceledAt,
                'grace_ends_at' => $graceEndsAt,
                'metadata' => $projection->metadata,
            ],
        );
    }
}
