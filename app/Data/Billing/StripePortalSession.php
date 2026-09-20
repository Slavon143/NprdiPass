<?php

namespace App\Data\Billing;

class StripePortalSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
    ) {}
}
