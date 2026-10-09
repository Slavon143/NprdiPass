<?php

namespace App\Actions\Webhooks;

use App\Authorization\ApiTokenMutationAuthorizer;
use App\Enums\ApiTokenAbility;
use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\Webhooks\WebhookEndpoint;
use Illuminate\Support\Facades\DB;

class DisableWebhookEndpoint
{
    public function __construct(private readonly ApiTokenMutationAuthorizer $authorizer) {}

    public function execute(User $actor, Company $company, string $uuid): WebhookEndpoint
    {
        return DB::transaction(function () use ($actor, $company, $uuid): WebhookEndpoint {
            $this->authorizer->authorize($actor, $company, ApiTokenAbility::WebhooksManage->value);
            $endpoint = WebhookEndpoint::query()->forCompany($company)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $endpoint->forceFill(['status' => WebhookEndpointStatus::Disabled])->save();

            return $endpoint;
        });
    }
}
