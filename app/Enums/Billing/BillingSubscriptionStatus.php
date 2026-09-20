<?php

namespace App\Enums\Billing;

enum BillingSubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case Pending = 'pending';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Unpaid = 'unpaid';
    case Canceled = 'canceled';

    public function grantsEntitlements(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    public function isNonTerminal(): bool
    {
        return in_array($this, [
            self::Incomplete,
            self::Pending,
            self::Trialing,
            self::Active,
            self::PastDue,
            self::Unpaid,
        ], true);
    }
}
