<?php

namespace App\Services\Billing;

use App\Models\Billing\CompanySubscription;
use App\Models\Billing\EntitlementOverride;
use App\Models\Company;
use Illuminate\Validation\ValidationException;

class EntitlementService
{
    public function value(Company $company, string $key): mixed
    {
        $override = EntitlementOverride::query()
            ->forCompany($company)
            ->active()
            ->where('key', $key)
            ->latest()
            ->first();

        if ($override instanceof EntitlementOverride) {
            return $override->getAttribute('value');
        }

        $subscription = $company->subscription()->first();
        if ($subscription instanceof CompanySubscription) {
            if (! $subscription->grantsEntitlements()) {
                return null;
            }

            $entitlements = $subscription->entitlements ?? [];

            return $entitlements[$key] ?? null;
        }

        // The default-plan fallback only exists to keep local/testing demo environments usable
        // without a real Stripe account. With the Stripe provider, a company without a confirmed
        // subscription has no commercial entitlements at all.
        if ((string) config('billing.provider') !== 'fake') {
            return null;
        }

        /** @var array<string, mixed> $plan */
        $plan = (array) config('billing.plans.'.config('billing.default_plan', 'pilot'), []);
        $entitlements = is_array($plan['entitlements'] ?? null) ? $plan['entitlements'] : [];

        return $entitlements[$key] ?? null;
    }

    public function enabled(Company $company, string $key): bool
    {
        return $this->value($company, $key) === true;
    }

    public function limit(Company $company, string $key): ?int
    {
        $value = $this->value($company, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    public function assertBelowLimit(Company $company, string $key, int $currentCount): void
    {
        $limit = $this->limit($company, $key);

        if ($limit !== null && $currentCount >= $limit) {
            throw ValidationException::withMessages([
                'entitlement' => "The {$key} entitlement limit of {$limit} has been reached.",
            ]);
        }
    }

    public function assertEnabled(Company $company, string $key): void
    {
        if (! $this->enabled($company, $key)) {
            throw ValidationException::withMessages([
                'entitlement' => "The {$key} entitlement is not enabled for this company.",
            ]);
        }
    }
}
