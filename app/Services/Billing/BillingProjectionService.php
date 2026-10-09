<?php

namespace App\Services\Billing;

use App\Data\Billing\StripeSubscriptionProjection;
use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

class BillingProjectionService
{
    public function upsert(Company $company, StripeSubscriptionProjection $projection): CompanySubscription
    {
        return DB::transaction(function () use ($company, $projection): CompanySubscription {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $existing = CompanySubscription::query()->forCompany($company)->lockForUpdate()->first();
            $existingGraceEnd = $existing instanceof CompanySubscription ? $existing->grace_ends_at : null;
            $graceEndsAt = match ($projection->status) {
                BillingSubscriptionStatus::PastDue => $existingGraceEnd ?? now()->addDays(7),
                BillingSubscriptionStatus::Active, BillingSubscriptionStatus::Trialing => null,
                default => $existingGraceEnd,
            };

            $commercial = ($projection->metadata['commercial_model'] ?? '') === 'v1';
            $preserveLegacy = ! $commercial && is_array(config('billing.legacy_plans.'.$projection->planKey)) && $existing instanceof CompanySubscription
                && ($existing->metadata['commercial_model'] ?? null) !== 'v1'
                && $existing->plan_key === $projection->planKey
                && $existing->stripe_price_id === $projection->stripePriceId
                && is_array($existing->entitlements);
            $entitlements = $preserveLegacy ? $existing->entitlements
                : app(CommercialPlanCatalog::class)->entitlements($projection->planKey, $projection->status, $commercial);

            if ($commercial || ($projection->metadata['commercial_model'] ?? '') === 'unknown') {
                Company::query()->whereKey($company->getKey())->update(['commercial_model_version' => 1]);
            }

            $subscription = $existing ?? new CompanySubscription;
            $subscription->forceFill([
                'company_id' => $company->getKey(),
                'stripe_customer_id' => $projection->stripeCustomerId,
                'stripe_subscription_id' => $projection->stripeSubscriptionId,
                'stripe_price_id' => $projection->stripePriceId,
                'plan_key' => $projection->planKey,
                'status' => $projection->status,
                'entitlements' => $entitlements,
                'trial_ends_at' => $projection->trialEndsAt,
                'current_period_start' => $projection->currentPeriodStart,
                'current_period_end' => $projection->currentPeriodEnd,
                'canceled_at' => $projection->canceledAt,
                'grace_ends_at' => $graceEndsAt,
                'metadata' => $projection->metadata,
            ])->save();

            return $subscription;
        });
    }
}
