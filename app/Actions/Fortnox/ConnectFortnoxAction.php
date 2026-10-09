<?php

namespace App\Actions\Fortnox;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\Fortnox\FortnoxOAuthState;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Fortnox\FortnoxOAuthService;
use Illuminate\Support\Facades\DB;

class ConnectFortnoxAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly FortnoxOAuthService $oauth,
        private readonly EntitlementService $entitlements,
    ) {}

    public function execute(User $actor, Company $company): string
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
        $this->entitlements->assertEnabled($company, 'fortnox.enabled');

        $state = bin2hex(random_bytes(48));
        $ttl = max(1, (int) config('fortnox.oauth_state_ttl_minutes'));

        DB::transaction(function () use ($actor, $company, $state, $ttl): void {
            $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
            FortnoxOAuthState::query()->create([
                'company_id' => $company->getKey(),
                'user_id' => $actor->getKey(),
                'state_hash' => hash('sha256', $state),
                'expires_at' => now()->addMinutes($ttl),
            ]);
        });

        return $this->oauth->authorizationUrl($state);
    }
}
