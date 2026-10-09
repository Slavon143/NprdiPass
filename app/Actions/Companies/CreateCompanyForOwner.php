<?php

namespace App\Actions\Companies;

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CreateCompanyForOwner
{
    public function execute(User $owner, string $name, ?string $organizationNumber = null, ?string $countryCode = null): Company
    {
        return DB::transaction(function () use ($owner, $name, $organizationNumber, $countryCode): Company {
            // There is no existing tenant to authorize yet. Protect the current
            // account before creating the new tenant and its membership.
            $owner = User::query()->whereKey($owner->getKey())->sharedLock()->first();
            if ($owner?->status !== UserStatus::Active) {
                throw new AuthorizationException;
            }
            $company = Company::query()->create([
                'name' => trim($name),
                'organization_number' => $organizationNumber !== null ? trim($organizationNumber) : null,
                ...($countryCode !== null ? ['country_code' => strtoupper(trim($countryCode))] : []),
                'billing_email' => $owner->email,
                'status' => CompanyStatus::Active,
            ]);

            CompanyMembership::query()->create([
                'company_id' => $company->getKey(),
                'user_id' => $owner->getKey(),
                'role' => CompanyRole::Owner,
                'joined_at' => now(),
            ]);

            $commercial = (bool) config('billing.commercial_enabled', false);
            $plan = (string) config($commercial ? 'billing.trial.plan_key' : 'billing.default_plan', $commercial ? 'growth' : 'pilot');
            $trial = $commercial ? (array) config('billing.trial', []) : (array) config('billing.plans.'.$plan, []);
            $trial['days'] = $commercial ? ($trial['days'] ?? 14) : ($trial['trial_days'] ?? 14);
            $trialEndsAt = now()->addDays(max(1, (int) $trial['days']));
            if ($commercial) {
                $company->forceFill(['commercial_model_version' => 1])->save();
            }
            CompanySubscription::query()->create([
                'company_id' => $company->getKey(),
                'plan_key' => $plan,
                'status' => BillingSubscriptionStatus::Trialing,
                'entitlements' => $trial['entitlements'] ?? [],
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => now(),
                'current_period_end' => $trialEndsAt,
                'metadata' => $commercial
                    ? ['source' => 'self_serve_registration', 'commercial_model' => 'v1', 'trial_started_at' => now()->toISOString()]
                    : ['source' => 'self_serve_registration'],
            ]);

            return $company->refresh();
        });
    }
}
