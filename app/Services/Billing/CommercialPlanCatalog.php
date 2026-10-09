<?php

namespace App\Services\Billing;

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use Illuminate\Validation\ValidationException;

class CommercialPlanCatalog
{
    /** @return array<string, mixed> */
    public function plan(string $key): array
    {
        $plan = config('billing.plans.'.$key);
        if (! is_array($plan)) {
            throw ValidationException::withMessages(['plan' => 'The selected billing plan is not available.']);
        }

        return $plan;
    }

    /** @return array<string, mixed> */
    public function checkoutPlan(string $key, string $interval): array
    {
        if (! in_array($interval, ['month', 'year'], true)) {
            throw ValidationException::withMessages(['interval' => 'Select monthly or annual billing.']);
        }
        $plan = $this->plan($key);
        if (($plan['self_service'] ?? true) !== true) {
            throw ValidationException::withMessages(['plan' => 'This plan is not available for new self-service purchases.']);
        }
        if (($plan['contact_sales'] ?? false) === true) {
            throw ValidationException::withMessages(['plan' => 'Contact sales to arrange an Enterprise contract.']);
        }

        return $plan;
    }

    /** @return array{plan_key: string, interval: string, commercial_model: string}|null */
    public function price(string $priceId): ?array
    {
        if ($priceId === '') {
            return null;
        }
        $matches = [];
        foreach ((array) config('billing.plans') as $key => $plan) {
            foreach (['month' => $plan['stripe_price_id'] ?? '', 'year' => $plan['stripe_annual_price_id'] ?? ''] as $interval => $id) {
                if (is_string($id) && $id !== '' && hash_equals($id, $priceId)) {
                    $matches[] = ['plan_key' => (string) $key, 'interval' => $interval,
                        'commercial_model' => ($plan['commercial_model'] ?? '') === 'v1' ? 'v1' : 'legacy'];
                }
            }
        }
        foreach ((array) config('billing.legacy_prices') as $key => $id) {
            if (is_string($id) && $id !== '' && hash_equals($id, $priceId)) {
                $matches[] = ['plan_key' => (string) $key, 'interval' => 'month', 'commercial_model' => 'legacy'];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array<string, mixed> */
    public function entitlements(string $key, BillingSubscriptionStatus $status, bool $commercial): array
    {
        if ($commercial && $status === BillingSubscriptionStatus::Trialing) {
            return (array) config('billing.trial.entitlements', []);
        }
        if (! $commercial) {
            return (array) config('billing.legacy_plans.'.$key.'.entitlements', config('billing.plans.'.$key.'.entitlements', []));
        }

        return (array) ($this->plan($key)['entitlements'] ?? []);
    }

    public function isCommercial(?CompanySubscription $subscription): bool
    {
        return ($subscription?->metadata['commercial_model'] ?? null) === 'v1';
    }
}
