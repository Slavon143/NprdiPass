<?php

namespace App\Data\Billing;

use App\Enums\Billing\BillingSubscriptionStatus;
use Carbon\CarbonImmutable;

class StripeSubscriptionProjection
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $stripeCustomerId,
        public readonly ?string $stripeSubscriptionId,
        public readonly ?string $stripePriceId,
        public readonly string $planKey,
        public readonly BillingSubscriptionStatus $status,
        public readonly ?CarbonImmutable $trialEndsAt = null,
        public readonly ?CarbonImmutable $currentPeriodStart = null,
        public readonly ?CarbonImmutable $currentPeriodEnd = null,
        public readonly ?CarbonImmutable $canceledAt = null,
        public readonly array $metadata = [],
    ) {}
}
