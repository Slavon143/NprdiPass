<?php

namespace App\Console\Commands;

use App\Enums\Webhooks\WebhookDeliveryStatus;
use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\Webhooks\WebhookDelivery;
use Illuminate\Console\Command;

class RecoverWebhookDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:recover {--limit=100 : Maximum pending deliveries to requeue}';

    protected $description = 'Recover committed webhook deliveries whose dispatch or worker was interrupted';

    public function handle(): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $deliveries = WebhookDelivery::query()->where('status', WebhookDeliveryStatus::Pending->value)
            ->where(function ($query): void {
                $query->where('next_attempt_at', '<=', now())
                    ->orWhere(fn ($query) => $query->whereNull('next_attempt_at')->where('created_at', '<=', now()->subMinutes(5)));
            })
            ->whereHas('endpoint', fn ($query) => $query->where('status', WebhookEndpointStatus::Active->value)
                ->whereColumn('webhook_endpoints.company_id', 'webhook_deliveries.company_id'))
            ->orderBy('id')->limit($limit)->get(['id', 'company_id']);
        foreach ($deliveries as $delivery) {
            DeliverWebhookJob::dispatch($delivery->id, $delivery->company_id);
        }
        $this->info($deliveries->count().' pending webhook deliveries requeued.');

        return self::SUCCESS;
    }
}
