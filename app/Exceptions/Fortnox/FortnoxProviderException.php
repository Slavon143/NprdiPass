<?php

namespace App\Exceptions\Fortnox;

use RuntimeException;

class FortnoxProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly int $status = 0,
    ) {
        parent::__construct($message);
    }

    public static function retryable(string $message, int $status = 0): self
    {
        return new self($message, true, $status);
    }

    public static function unauthorized(string $message = 'Fortnox rejected the request credentials.', int $status = 401): self
    {
        return new self($message, false, $status);
    }
}
