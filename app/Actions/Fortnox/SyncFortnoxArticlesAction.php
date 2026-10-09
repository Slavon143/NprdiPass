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
use App\Services\Fortnox\FortnoxSyncMutex;
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
        $mutex = app(FortnoxSyncMutex::class);

        return $mutex->run($company, fn (): FortnoxSyncRun => $this->executeLocked($actor, $company, $trigger, $mutex));
    }

    private function executeLocked(User $actor, Company $company, FortnoxSyncTrigger $trigger, FortnoxSyncMutex $mutex): FortnoxSyncRun
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);

        // The connection row lock is held for the whole resolveRun transaction,
        // so concurrent triggers for the same connection serialize here: the
        // second transaction cannot observe "no running sync" and create a
        // duplicate run.
        $resolved = DB::transaction(function () use ($actor, $company, $trigger): array {
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

            return [$connection, $run];
        });

        [$connection, $run] = $resolved;

        if ($run->status === FortnoxSyncRunStatus::Completed && $run->pagination_complete) {
            return $run->refresh();
        }

        $this->auditLogger->logTenant($company, AuditEvent::FortnoxSyncStarted, $actor, $run, [
            'sync_run_uuid' => $run->uuid,
            'trigger_type' => $run->trigger_type->value,
            'watermark_before' => $run->watermark_before?->toIso8601String(),
            'resumed_from_cursor' => $run->cursor_page,
        ]);

        try {
            $run = $this->syncService->execute($actor, $company, $connection, $run, $mutex->assertHeld(...));

            $this->auditLogger->logTenant($company, AuditEvent::FortnoxSyncCompleted, $actor, $run, [
                'sync_run_uuid' => $run->uuid,
                'articles_fetched' => $run->articles_fetched,
                'articles_created' => $run->articles_created,
                'articles_updated' => $run->articles_updated,
                'articles_skipped' => $run->articles_skipped,
                'conflicts_raised' => $run->conflicts_raised,
                'pagination_complete' => $run->pagination_complete,
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
        // The committed watermark identifies unfinished work across triggers.
        // always resolves to the same logical sync attempt. The stored
        // idempotency_key stays unique per run row (DB constraint), while
        // dedupe_key drives lookup and resume.
        $dedupeKey = hash('sha256', implode('|', [
            $connection->getKey(),
            $connection->last_synced_at?->toIso8601String() ?? 'initial',
        ]));

        $existing = FortnoxSyncRun::query()
            ->forCompany($company)
            ->where('connection_id', $connection->getKey())
            ->where(function ($query): void {
                $query->where('status', FortnoxSyncRunStatus::Running->value)
                    ->orWhere(function ($query): void {
                        $query->where('status', FortnoxSyncRunStatus::Completed->value)->where('pagination_complete', false);
                    });
            })
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            return FortnoxSyncRun::query()->create([
                'company_id' => $company->getKey(),
                'connection_id' => $connection->getKey(),
                'trigger_type' => $trigger,
                'status' => FortnoxSyncRunStatus::Running,
                'idempotency_key' => hash('sha256', $dedupeKey.'|'.bin2hex(random_bytes(16))),
                'dedupe_key' => $dedupeKey,
                'watermark_before' => $connection->last_synced_at,
                'cursor_page' => null,
                'pagination_complete' => false,
                'started_at' => now(),
                'triggered_by' => $actor->getKey(),
            ]);
        }

        if ($existing->status === FortnoxSyncRunStatus::Completed) {
            if ($existing->pagination_complete) {
                return $existing;
            }

            // A previous run hit the per-run article cap without exhausting
            // pagination; the committed watermark was not advanced. Resume the
            // same logical attempt from the saved cursor.
            $existing->forceFill([
                'status' => FortnoxSyncRunStatus::Running,
                'failure_message' => null,
                'started_at' => now(),
            ])->save();

            return $existing;
        }

        if ($existing->status === FortnoxSyncRunStatus::Running) {
            $staleMinutes = max(1, (int) config('fortnox.sync_run_stale_minutes'));
            $startedAt = $existing->started_at;

            if ($startedAt === null || $startedAt->greaterThan(now()->subMinutes($staleMinutes))) {
                throw ValidationException::withMessages(['sync' => 'A Fortnox sync is already in progress for this connection.']);
            }

            // Abandoned run (crashed process): resume from its saved cursor.
            $existing->forceFill(['started_at' => now()])->save();

            return $existing;
        }

        // Failed run: create a fresh attempt for the same logical sync. The
        // applier is idempotent (fortnox_last_modified skip logic), so refetching
        // from the committed watermark is safe.
        return FortnoxSyncRun::query()->create([
            'company_id' => $company->getKey(),
            'connection_id' => $connection->getKey(),
            'trigger_type' => $trigger,
            'status' => FortnoxSyncRunStatus::Running,
            'idempotency_key' => hash('sha256', $dedupeKey.'|'.bin2hex(random_bytes(16))),
            'dedupe_key' => $dedupeKey,
            'watermark_before' => $connection->last_synced_at,
            'cursor_page' => null,
            'pagination_complete' => false,
            'started_at' => now(),
            'triggered_by' => $actor->getKey(),
        ]);
    }
}
