<?php

namespace App\Exceptions\Webhooks;

use RuntimeException;

class RetryableWebhookDeliveryException extends RuntimeException
{
    public function __construct(string $message, public readonly int $responseStatus)
    {
        parent::__construct($message);
    }
}
