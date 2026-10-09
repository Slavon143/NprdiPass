<?php

namespace App\Actions\Fortnox;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Models\Company;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DisconnectFortnoxAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(User $actor, Company $company): FortnoxConnection
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);

        return DB::transaction(function () use ($actor, $company): FortnoxConnection {
            $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
            $connection = FortnoxConnection::query()
                ->forCompany($company)
                ->lockForUpdate()
                ->first();

            if ($connection === null || $connection->status === FortnoxConnectionStatus::Revoked) {
                throw ValidationException::withMessages(['connection' => 'Fortnox is not connected.']);
            }

            $connection->forceFill([
                'status' => FortnoxConnectionStatus::Revoked,
                'token_payload' => null,
                'token_expires_at' => null,
                'failure_message' => null,
            ])->save();

            $this->auditLogger->logTenant($company, AuditEvent::FortnoxConnectionDisconnected, $actor, $connection, [
                'connection_uuid' => $connection->uuid,
            ]);

            return $connection;
        });
    }
}
