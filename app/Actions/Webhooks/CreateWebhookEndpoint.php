<?php

namespace App\Actions\Webhooks;

use App\Authorization\ApiTokenMutationAuthorizer;
use App\Enums\ApiTokenAbility;
use App\Enums\Webhooks\WebhookEndpointStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\Webhooks\WebhookEndpoint;
use Illuminate\Support\Facades\DB;

class CreateWebhookEndpoint
{
    public function __construct(private readonly ApiTokenMutationAuthorizer $authorizer) {}

    /** @param array{url: string, secret: string, events: list<string>} $data */
    public function execute(User $actor, Company $company, array $data): WebhookEndpoint
    {
        return DB::transaction(function () use ($actor, $company, $data): WebhookEndpoint {
            $this->authorizer->authorize($actor, $company, ApiTokenAbility::WebhooksManage->value);

            return WebhookEndpoint::query()->create([
                'company_id' => $company->getKey(),
                'created_by' => $actor->getKey(),
                'url' => $data['url'], 'secret' => $data['secret'],
                'events' => $data['events'], 'status' => WebhookEndpointStatus::Active,
            ]);
        });
    }
}
