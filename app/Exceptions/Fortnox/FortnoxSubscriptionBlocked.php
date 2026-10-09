<?php

namespace App\Exceptions\Fortnox;

class FortnoxSubscriptionBlocked extends FortnoxProviderException
{
    public function __construct(string $message)
    {
        parent::__construct($message, false, 403);
    }
}
