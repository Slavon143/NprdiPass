<?php

namespace App\Enums\Webhooks;

enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case DeadLetter = 'dead_letter';
}
