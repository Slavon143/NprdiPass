<?php

namespace App\Actions\Webhooks;

use App\Authorization\ApiTokenMutationAuthorizer;
use App\Enums\ApiTokenAbility;
use App\Models\Company;
use App\Models\User;
use App\Models\Webhooks\WebhookEndpoint;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateWebhookEndpoint
{
    public function __construct(private readonly ApiTokenMutationAuthorizer $authorizer) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Company $company, string $uuid, array $data): WebhookEndpoint
    {
        return DB::transaction(function () use ($actor, $company, $uuid, $data): WebhookEndpoint {
            $this->authorizer->authorize($actor, $company, ApiTokenAbility::WebhooksManage->value);
            $endpoint = WebhookEndpoint::query()->forCompany($company)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $endpoint->fill(Arr::only($data, ['url', 'secret', 'events', 'status']))->save();

            return $endpoint;
        });
    }
}
