<?php

namespace App\Services\Webhooks;

use App\Enums\Webhooks\WebhookEventType;
use App\Models\Company;
use Illuminate\Support\Str;

class WebhookPayloadFactory
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function make(
        Company $company,
        WebhookEventType $eventType,
        array $data,
        ?string $eventId = null,
    ): array {
        return [
            'id' => $eventId ?? (string) Str::uuid(),
            'type' => $eventType->value,
            'created_at' => now()->toISOString(),
            'data' => $data,
            'meta' => [
                'api_version' => '2026-09-19',
                'company_uuid' => $company->uuid,
            ],
        ];
    }
}
