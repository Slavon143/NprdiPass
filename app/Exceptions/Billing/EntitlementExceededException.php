<?php

namespace App\Exceptions\Billing;

use RuntimeException;

class EntitlementExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $entitlement,
        public readonly int $limit,
    ) {
        parent::__construct("The {$entitlement} entitlement limit of {$limit} has been reached.");
    }
}
