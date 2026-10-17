<?php

namespace App\Actions\Fortnox;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Fortnox\FortnoxConnectionStatus;
use App\Enums\Fortnox\FortnoxSyncMode;
use App\Enums\Fortnox\FortnoxSyncRunStatus;
use App\Enums\Fortnox\FortnoxSyncTrigger;
use App\Exceptions\Fortnox\FortnoxProviderException;
use App\Models\Company;
use App\Models\Fortnox\FortnoxConnection;
use App\Models\Fortnox\FortnoxSyncRun;
use App\Models\User;
use App\Services\Fortnox\FortnoxSyncMutex;
use App\Services\Fortnox\FortnoxSyncService;
use Carbon\CarbonImmutable;
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
    public function execute(User $actor, Company $company, FortnoxSyncTrigger $trigger = FortnoxSyncTrigger::Manual,
        FortnoxSyncMode $mode = FortnoxSyncMode::Incremental, ?string $expectedConnectionUuid = null, ?CarbonImmutable $expectedWatermark = null): FortnoxSyncRun
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);
        if ($mode === FortnoxSyncMode::Full && app()->environment('production')) {
            throw ValidationException::withMessages(['sync' => 'Full catalogue preview and import are limited to the test environment.']);
        }
        $mutex = app(FortnoxSyncMutex::class);

        return $mutex->run($company, fn (): FortnoxSyncRun => $this->executeLocked($actor, $company, $trigger, $mutex, $mode, $expectedConnectionUuid, $expectedWatermark));
    }

    private function executeLocked(User $actor, Company $company, FortnoxSyncTrigger $trigger, FortnoxSyncMutex $mutex,
        FortnoxSyncMode $mode, ?string $expectedConnectionUuid, ?CarbonImmutable $expectedWatermark): FortnoxSyncRun
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::FortnoxManage);

        // The connection row lock is held for the whole resolveRun transaction,
        // so concurrent triggers for the same connection serialize here: the
        // second transaction cannot observe "no running sync" and create a
        // duplicate run.
        $resolved = DB::transaction(function () use ($actor, $company, $trigger, $mode, $expectedConnectionUuid, $expectedWatermark): array {
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

            if ($expectedConnectionUuid !== null && ($connection->uuid !== $expectedConnectionUuid
                || $connection->last_synced_at?->toIso8601String() !== $expectedWatermark?->toIso8601String())) {
                throw ValidationException::withMessages(['preview' => 'The connection or data watermark changed. Preview the full catalogue again.']);
            }
            $run = $this->resolveRun($actor, $company, $connection, $trigger, $mode);

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
            'sync_mode' => $run->sync_mode?->value,
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
                'sync_mode' => $run->sync_mode?->value,
                'pages_fetched' => $run->pages_fetched,
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

    private function resolveRun(User $actor, Company $company, FortnoxConnection $connection, FortnoxSyncTrigger $trigger, FortnoxSyncMode $mode): FortnoxSyncRun
    {
        // The committed watermark identifies unfinished work across triggers.
        // always resolves to the same logical sync attempt. The stored
        // idempotency_key stays unique per run row (DB constraint), while
        // dedupe_key drives lookup and resume.
        $dedupeKey = hash('sha256', implode('|', [
            $connection->getKey(),
            $connection->last_synced_at?->toIso8601String() ?? 'initial',
        ]));

        $latestRunId = FortnoxSyncRun::query()->forCompany($company)->where('connection_id', $connection->getKey())->latest('id')->value('id');
        $existing = FortnoxSyncRun::query()
            ->forCompany($company)
            ->where('connection_id', $connection->getKey())
            ->where(function ($query) use ($connection, $latestRunId): void {
                $query->where('status', FortnoxSyncRunStatus::Running->value)
                    ->orWhere(function ($query): void {
                        $query->where('status', FortnoxSyncRunStatus::Completed->value)->where('pagination_complete', false);
                    })
                    ->orWhere(function ($query) use ($connection, $latestRunId): void {
                        $query->whereKey($latestRunId)->where('status', FortnoxSyncRunStatus::Failed->value)->where('sync_mode', FortnoxSyncMode::Full->value);
                        $connection->last_synced_at === null ? $query->whereNull('watermark_before') : $query->where('watermark_before', $connection->last_synced_at);
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
                'sync_mode' => $mode === FortnoxSyncMode::Full || $connection->last_synced_at === null ? FortnoxSyncMode::Full : FortnoxSyncMode::Incremental,
                'pages_fetched' => 0,
                'cursor_page' => null,
                'pagination_complete' => false,
                'started_at' => now(),
                'completed_at' => null,
                'triggered_by' => $actor->getKey(),
            ]);
        }

        if ($mode === FortnoxSyncMode::Full && $existing->sync_mode !== FortnoxSyncMode::Full) {
            throw ValidationException::withMessages(['sync' => 'Complete the current incremental sync before starting a full catalogue import.']);
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
                'completed_at' => null,
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
            'sync_mode' => $existing->sync_mode ?? $mode,
            'pages_fetched' => 0,
            'cursor_page' => null,
            'pagination_complete' => false,
            'started_at' => now(),
            'triggered_by' => $actor->getKey(),
        ]);
    }
}
