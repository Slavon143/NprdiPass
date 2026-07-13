<?php

namespace App\Actions\Api;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RevokeCompanyApiToken
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(User $actor, Company $company, PersonalAccessToken $token): void
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::ApiTokensRevoke);

        DB::transaction(function () use ($actor, $company, $token): void {
            $lockedToken = PersonalAccessToken::query()
                ->whereKey($token->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $properties = [
                'token_name' => $lockedToken->name,
                'abilities' => array_values($lockedToken->abilities),
                'expires_at' => $lockedToken->expires_at?->toAtomString(),
                'created_by' => $lockedToken->tokenable instanceof User
                    ? $lockedToken->tokenable->uuid
                    : null,
            ];

            $lockedToken->delete();
            $this->auditLogger->logTenant(
                $company,
                AuditEvent::ApiTokenRevoked,
                $actor,
                'API token: '.$lockedToken->name,
                $properties,
            );
        });
    }
}
