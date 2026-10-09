<?php

namespace App\Models\Webhooks;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $attempt
 * @property string $status
 * @property int|null $response_status
 * @property string|null $error_code
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
class WebhookDeliveryAttempt extends Model
{
    protected $fillable = [
        'webhook_delivery_id', 'attempt', 'status', 'response_status',
        'response_body_excerpt', 'error_code', 'error_message', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }
}
