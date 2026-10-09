<?php

namespace App\Services\Billing;

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Billing\CompanySubscription;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class CommercialUsageWindow
{
    /** @return array{start: CarbonImmutable, end: CarbonImmutable} */
    public function resolve(CompanySubscription $subscription, CarbonImmutable $at): array
    {
        $license = $subscription->localTrialBeforeCheckout() ?? $subscription;
        $start = $license->current_period_start?->utc();
        $end = $license->status === BillingSubscriptionStatus::Trialing
            ? $license->trial_ends_at?->utc() : $license->current_period_end?->utc();
        if ($start === null || $end === null || $start->greaterThan($at) || $end->lessThanOrEqualTo($at)) {
            throw ValidationException::withMessages(['entitlement' => 'A current confirmed quota period is unavailable.']);
        }
        if ($license->status !== BillingSubscriptionStatus::Trialing && ($license->metadata['billing_interval'] ?? null) === 'year') {
            // Recompute every boundary from the original anchor: Jan 31 -> Feb 28 -> Mar 31.
            $months = ($at->year - $start->year) * 12 + $at->month - $start->month;
            if ($start->addMonthsNoOverflow($months)->greaterThan($at)) {
                $months--;
            }
            $next = $start->addMonthsNoOverflow($months + 1);
            $start = $start->addMonthsNoOverflow($months);
            $end = $next->lessThan($end) ? $next : $end;
        }

        return ['start' => $start, 'end' => $end];
    }
}
