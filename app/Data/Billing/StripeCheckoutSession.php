<?php

namespace App\Data\Billing;

class StripeCheckoutSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
    ) {}
}
