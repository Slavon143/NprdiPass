<?php

namespace App\Actions\Fortnox;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Enums\Fortnox\FortnoxSyncTrigger;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Models\Company;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Models\User;
use App\Services\Fortnox\FortnoxSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SyncFortnoxArticlesAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly FortnoxSyncService $syncService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @throws ValidationException
     * @throws FortnoxProviderException
     */
    public function execute(User $actor, Company $company, FortnoxSyncTrigger $trigger = FortnoxSyncTrigger::Manual): FortnoxSyncRun
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);

        $connection = FortnoxConnection::query()
            ->forCompany($company)
            ->lockForUpdate()
            ->first();

        if ($connection === null || $connection->status === FortnoxConnectionStatus::Revoked) {
            throw ValidationException::withMessages(['connection' => 'Fortnox is not connected.']);
        }

        if ($connection->status === FortnoxConnectionStatus::ReconnectRequired) {
            throw ValidationException::withMessages(['connection' => 'Fortnox requires re-authentication before syncing.']);
        }

        $run = $this->resolveRun($actor, $company, $connection, $trigger);

        if ($run->status === FortnoxSyncRunStatus::Completed) {
            return $run->refresh();
        }

        $this->auditLogger->logTenant($company, AuditEvent::FortnoxSyncStarted, $actor, $run, [
            'sync_run_uuid' => $run->uuid,
            'trigger_type' => $run->trigger_type->value,
        ]);

        try {
            $run = $this->syncService->execute($actor, $company, $connection, $run);

            $this->auditLogger->logTenant($company, AuditEvent::FortnoxSyncCompleted, $actor, $run, [
                'sync_run_uuid' => $run->uuid,
                'articles_fetched' => $run->articles_fetched,
                'articles_created' => $run->articles_created,
                'articles_updated' => $run->articles_updated,
                'articles_skipped' => $run->articles_skipped,
                'conflicts_raised' => $run->conflicts_raised,
            ]);

            return $run;
        } catch (Throwable $exception) {
            $this->auditLogger->logTenant($company, AuditEvent::FortnoxSyncFailed, $actor, $run->fresh(), [
                'sync_run_uuid' => $run->uuid,
                'failure_message' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            throw $exception;
        }
    }

    private function resolveRun(User $actor, Company $company, FortnoxConnection $connection, FortnoxSyncTrigger $trigger): FortnoxSyncRun
    {
        $baseKey = hash('sha256', implode('|', [
            $connection->getKey(),
            $trigger->value,
            $connection->last_synced_at?->toIso8601String() ?? 'initial',
        ]));

        return DB::transaction(function () use ($actor, $company, $connection, $trigger, $baseKey): FortnoxSyncRun {
            $existing = FortnoxSyncRun::query()
                ->forCompany($company)
                ->where('idempotency_key', $baseKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status === FortnoxSyncRunStatus::Completed) {
                return $existing;
            }

            return FortnoxSyncRun::query()->create([
                'company_id' => $company->getKey(),
                'connection_id' => $connection->getKey(),
                'trigger_type' => $trigger,
                'status' => FortnoxSyncRunStatus::Running,
                'idempotency_key' => hash('sha256', $baseKey.'|'.bin2hex(random_bytes(16))),
                'started_at' => now(),
                'triggered_by' => $actor->getKey(),
            ]);
        });
    }
}
