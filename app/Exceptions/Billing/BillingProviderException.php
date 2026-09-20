<?php

namespace App\Exceptions\Billing;

use RuntimeException;

class BillingProviderException extends RuntimeException
{
    public function __construct(
        string $message = 'Billing provider request failed.',
        public readonly bool $retryable = false,
        public readonly int $status = 0,
    ) {
        parent::__construct($message, $status);
    }

    public static function retryable(string $message, int $status = 0): self
    {
        return new self($message, true, $status);
    }
}
