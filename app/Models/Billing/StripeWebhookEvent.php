<?php

namespace App\Models\Billing;

use App\Enums\Billing\StripeWebhookEventStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property StripeWebhookEventStatus $status
 * @property array<string, mixed> $payload
 */
class StripeWebhookEvent extends Model
{
    protected $fillable = [
        'stripe_event_id',
        'type',
        'livemode',
        'status',
        'payload',
        'attempts',
        'error_message',
        'received_at',
        'processed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'status' => StripeWebhookEventStatus::class,
            'payload' => 'encrypted:json',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
