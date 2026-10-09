<?php

namespace App\Actions\Fortnox;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Models\Company;
use App\Models\Fortnox\FortnoxArticleMapping;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResyncFortnoxAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(User $actor, Company $company, string $mode = 'watermark'): FortnoxConnection
    {
        if (! in_array($mode, ['watermark', 'full'], true)) {
            throw ValidationException::withMessages(['mode' => 'Resync mode must be "watermark" or "full".']);
        }

        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);

        return DB::transaction(function () use ($actor, $company, $mode): FortnoxConnection {
            $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
            $connection = FortnoxConnection::query()
                ->forCompany($company)
                ->lockForUpdate()
                ->first();

            if ($connection === null || $connection->status === FortnoxConnectionStatus::Revoked) {
                throw ValidationException::withMessages(['connection' => 'Fortnox is not connected.']);
            }

            $running = FortnoxSyncRun::query()
                ->forCompany($company)
                ->where('connection_id', $connection->getKey())
                ->where('status', FortnoxSyncRunStatus::Running)
                ->orderByDesc('id')
                ->first();

            if ($running instanceof FortnoxSyncRun) {
                $staleMinutes = max(1, (int) config('fortnox.sync_run_stale_minutes'));

                if ($running->started_at === null || $running->started_at->greaterThan(now()->subMinutes($staleMinutes))) {
                    throw ValidationException::withMessages(['sync' => 'A Fortnox sync is currently in progress; resync is not possible.']);
                }

                $running->forceFill([
                    'status' => FortnoxSyncRunStatus::Failed,
                    'failure_message' => 'Abandoned run closed by manual resync.',
                    'completed_at' => now(),
                ])->save();
            }

            $connection->forceFill(['last_synced_at' => null, 'failure_message' => null])->save();

            $mappingsCleared = 0;

            if ($mode === 'full') {
                $mappingsCleared = FortnoxArticleMapping::query()
                    ->forCompany($company)
                    ->where('connection_id', $connection->getKey())
                    ->update([
                        'fortnox_last_modified' => null,
                        'last_applied_values' => null,
                    ]);
            }

            $this->auditLogger->logTenant($company, AuditEvent::FortnoxResyncRequested, $actor, $connection, [
                'mode' => $mode,
                'mappings_cleared' => $mappingsCleared,
            ]);

            return $connection->refresh();
        });
    }
}
