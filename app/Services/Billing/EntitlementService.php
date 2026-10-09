<?php

namespace App\Services\Billing;

use App\Models\Billing\CompanySubscription;
use App\Models\Billing\EntitlementOverride;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class EntitlementService
{
    public function value(Company $company, string $key): mixed
    {
        return $this->resolveValue($company, $key, false);
    }

    public function valueForMutation(Company $company, string $key, bool $sharedCompany = false): mixed
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Entitlement mutation reads require a transaction.');
        }
        $companies = Company::query()->whereKey($company->getKey());
        ($sharedCompany ? $companies->sharedLock() : $companies->lockForUpdate())->firstOrFail();

        return $this->resolveValue($company, $key, true);
    }

    public function limitForMutation(Company $company, string $key, bool $sharedCompany = false): ?int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Entitlement mutation reads require a transaction.');
        }
        // Keep Company first, including callers with an older outer snapshot.
        // The selected policy must remain stable until the admitted write commits.
        $companies = Company::query()->whereKey($company->getKey());
        ($sharedCompany ? $companies->sharedLock() : $companies->lockForUpdate())->firstOrFail();
        $value = $this->resolveValue($company, $key, true, true);

        return is_numeric($value) ? (int) $value : null;
    }

    private function resolveValue(Company $company, string $key, bool $currentRead, bool $numericLimit = false): mixed
    {
        $overrides = EntitlementOverride::query()
            ->forCompany($company)
            ->active()
            ->where('key', $key)
            ->latest();
        $override = ($currentRead ? $overrides->sharedLock() : $overrides)->first();

        if ($override instanceof EntitlementOverride) {
            return $override->getAttribute('value');
        }

        $subscriptions = $company->subscription();
        $subscription = ($currentRead ? $subscriptions->sharedLock() : $subscriptions)->first();
        if ($subscription instanceof CompanySubscription) {
            if (! $subscription->grantsEntitlements()) {
                return $numericLimit ? 0 : null;
            }

            $license = $subscription->localTrialBeforeCheckout() ?? $subscription;
            $entitlements = $license->entitlements ?? [];

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
        $value = $this->resolveValue($company, $key, false, true);

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
