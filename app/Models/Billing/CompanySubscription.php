<?php

namespace App\Models\Billing;

use App\Enums\Billing\BillingSubscriptionStatus;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property BillingSubscriptionStatus $status
 * @property array<string, mixed>|null $entitlements
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable|null $canceled_at
 * @property CarbonImmutable|null $grace_ends_at
 * @property CarbonImmutable|null $last_reconciled_at
 * @property Company|null $company
 */
class CompanySubscription extends Model
{
    protected $fillable = [
        'company_id',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'plan_key',
        'status',
        'entitlements',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'canceled_at',
        'grace_ends_at',
        'last_reconciled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => BillingSubscriptionStatus::class,
            'entitlements' => 'array',
            'trial_ends_at' => 'immutable_datetime',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'last_reconciled_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @param Builder<CompanySubscription> $query */
    public function scopeForCompany(Builder $query, Company $company): void
    {
        $query->where('company_id', $company->getKey());
    }

    public function grantsEntitlements(): bool
    {
        if (($this->metadata['commercial_model'] ?? null) === 'unknown') {
            return false;
        }

        if ($this->status === BillingSubscriptionStatus::Pending) {
            return $this->localTrialBeforeCheckout()?->grantsEntitlements() ?? false;
        }

        if ($this->status === BillingSubscriptionStatus::Trialing) {
            return $this->trial_ends_at instanceof CarbonInterface
                ? $this->trial_ends_at->isFuture()
                : ($this->metadata['commercial_model'] ?? null) !== 'v1';
        }

        if ($this->status === BillingSubscriptionStatus::Active) {
            return true;
        }

        if ($this->status !== BillingSubscriptionStatus::PastDue) {
            return false;
        }

        $graceEndsAt = $this->grace_ends_at;

        return $graceEndsAt instanceof CarbonInterface
            ? $graceEndsAt->isFuture()
            : ($this->metadata['commercial_model'] ?? null) !== 'v1';
    }

    public function isNonTerminal(): bool
    {
        return $this->status->isNonTerminal();
    }

    public function localTrialBeforeCheckout(): ?self
    {
        // Only the nested snapshot written by our durable checkout claim can
        // retain a local license. Stripe metadata values are scalar strings.
        $metadata = $this->metadata ?? [];
        $pending = $metadata['pending_checkout'] ?? null;
        if ($this->status !== BillingSubscriptionStatus::Pending
            || ($metadata['commercial_model'] ?? null) !== 'v1'
            || ($metadata['source'] ?? null) !== 'self_serve_registration'
            || ! is_array($pending) || ! is_string($pending['claim_id'] ?? null)
            || ! Str::isUuid($pending['claim_id'])) {
            return null;
        }
        $previous = $pending['previous_state'] ?? null;
        if (! is_array($previous) || ($previous['status'] ?? null) !== BillingSubscriptionStatus::Trialing->value
            || ($previous['metadata']['commercial_model'] ?? null) !== 'v1'
            || ($previous['metadata']['source'] ?? null) !== 'self_serve_registration'
            || ($previous['stripe_subscription_id'] ?? null) !== null
            || ($previous['stripe_customer_id'] ?? null) !== null
            || ! is_string($previous['trial_ends_at'] ?? null)) {
            return null;
        }
        try {
            $trial = new self($previous);

            return $trial->trial_ends_at instanceof CarbonInterface ? $trial : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
