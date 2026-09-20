<?php

namespace App\Enums\Webhooks;

enum WebhookEndpointStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
