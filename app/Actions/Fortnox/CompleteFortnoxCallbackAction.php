<?php

namespace App\Actions\Fortnox;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Models\Company;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxOAuthState;
use App\Models\User;
use App\Services\Fortnox\FortnoxOAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteFortnoxCallbackAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly FortnoxOAuthService $oauth,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @throws ValidationException
     * @throws FortnoxProviderException
     */
    public function execute(User $actor, Company $company, string $state, string $code): FortnoxConnection
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);

        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'The authorization code is missing.']);
        }

        $stateRecord = $this->consumeState($company, $state);
        if ($stateRecord === null) {
            throw ValidationException::withMessages(['state' => 'The OAuth state is invalid, expired, or already used.']);
        }

        $token = $this->oauth->exchange($code);

        return DB::transaction(function () use ($actor, $company, $token): FortnoxConnection {
            $connection = FortnoxConnection::query()
                ->forCompany($company)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                $connection = new FortnoxConnection;
                $connection->forceFill(['company_id' => $company->getKey()]);
            }

            $scopes = $token->scope === null || $token->scope === ''
                ? null
                : array_values(array_filter(array_map('trim', explode(' ', $token->scope))));

            $connection->forceFill([
                'provider' => 'fortnox',
                'status' => FortnoxConnectionStatus::Active,
                'external_tenant_id' => $token->tenantId,
                'scopes' => $scopes,
                'token_payload' => [
                    'access_token' => $token->accessToken,
                    'refresh_token' => $token->refreshToken,
                    'scope' => $token->scope,
                ],
                'token_expires_at' => $token->expiresAt,
                'connected_by' => $actor->getKey(),
                'connected_at' => now(),
                'failure_message' => null,
            ])->save();

            $this->auditLogger->logTenant($company, AuditEvent::FortnoxConnectionConnected, $actor, $connection, [
                'connection_uuid' => $connection->uuid,
                'scopes' => $scopes,
                'external_tenant_id' => $token->tenantId,
            ]);

            return $connection;
        });
    }

    private function consumeState(Company $company, string $state): ?FortnoxOAuthState
    {
        $hash = hash('sha256', $state);

        return DB::transaction(function () use ($company, $hash): ?FortnoxOAuthState {
            $record = FortnoxOAuthState::query()
                ->where('company_id', $company->getKey())
                ->where('state_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($record === null || $record->consumed_at !== null || $record->expires_at->lessThanOrEqualTo(now())) {
                return null;
            }

            $record->forceFill(['consumed_at' => now()])->save();

            return $record;
        });
    }
}
