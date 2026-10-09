<?php

namespace App\Actions\Imports;

use App\Actions\Catalog\Attributes\SyncProductAttributeValuesAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Actions\Catalog\Variants\UpdateProductVariantAction;
use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Imports\ImportJobStatus;
use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportRecordStatus;
use App\Enums\Notifications\NotificationSeverity;
use App\Enums\Notifications\NotificationType;
use App\Enums\Webhooks\WebhookEventType;
use App\Exceptions\Billing\CommercialReadOnlyException;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\Imports\ImportError;
use App\Models\Imports\ImportJob;
use App\Models\Imports\ImportRecord;
use App\Models\User;
use App\Services\Catalog\CatalogWriteTransaction;
use App\Services\Notifications\NotificationCenter;
use App\Services\Webhooks\WebhookDeliveryService;
use App\Support\Catalog\VariantIdentifierNormalizer;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

class ApplyImportJobAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly CreateProductAction $createProduct,
        private readonly UpdateProductAction $updateProduct,
        private readonly SyncProductAttributeValuesAction $syncAttributes,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationCenter $notifications,
    ) {}

    public function execute(User $actor, Company $company, ImportJob $job): ImportJob
    {
        $this->assertJob($company, $job);

        $ownsTransaction = DB::transactionLevel() === 0;
        for ($attempt = 1; ; $attempt++) {
            $failure = null;
            try {
                $result = app(CatalogWriteTransaction::class)->run(function () use ($actor, $company, $job, &$failure): ImportJob {
                    $this->assertCanManage($actor, $company, $job);
                    $locked = ImportJob::query()->forCompany($company)->whereKey($job->getKey())->lockForUpdate()->firstOrFail();

                    return $this->executeLocked($actor, $company, $locked, $failure);
                });
            } catch (Throwable $exception) {
                // A savepoint cannot renew a transaction snapshot. Connection
                // loss has an uncertain commit outcome and must not be replayed.
                if ($ownsTransaction && $attempt < 3 && DB::connection()->transactionLevel() === 0
                    && $this->databaseFailureKind($exception) === 'transaction_retry') {
                    continue;
                }
                throw $exception;
            }
            if ($failure !== null) {
                throw $failure;
            }

            return $result;
        }
    }

    public function assertCanApply(User $actor, Company $company, ImportJob $job): void
    {
        $this->assertCanManage($actor, $company, $job);
        if (! in_array($job->status, [ImportJobStatus::Previewed, ImportJobStatus::Processing, ImportJobStatus::Failed], true)) {
            throw ValidationException::withMessages(['job' => 'Preview the import before applying it.']);
        }

        $path = Storage::disk((string) config('imports.disk'))->path($job->source_path);
        if (! is_file($path) || ! hash_equals($job->source_sha256, hash_file('sha256', $path))) {
            throw ValidationException::withMessages(['file' => 'The previewed import source is missing or has changed.']);
        }
    }

    public function assertCanManage(User $actor, Company $company, ImportJob $job): void
    {
        $this->assertJob($company, $job);
        $this->authorize($actor, $company, $job->mode);
    }

    private function executeLocked(User $actor, Company $company, ImportJob $job, ?Throwable &$failure): ImportJob
    {
        $this->authorize($actor, $company, $job->mode);
        if (in_array($job->status, [ImportJobStatus::Completed, ImportJobStatus::Cancelled], true)) {
            return $job;
        }
        $this->assertCanApply($actor, $company, $job);
        if ($job->cancel_requested_at !== null) {
            $job->forceFill(['status' => ImportJobStatus::Cancelled, 'completed_at' => now()])->save();

            return $job;
        }

        $job->forceFill(['status' => ImportJobStatus::Processing, 'started_at' => $job->started_at ?? now(), 'failure_message' => null])->save();
        try {
            $counts = $this->applyChunk($actor, $company, $job);
        } catch (Throwable $exception) {
            if ($this->databaseFailureKind($exception) !== null) {
                throw $exception;
            }
            $job->forceFill([
                'status' => ImportJobStatus::Failed,
                'failure_message' => mb_substr($exception->getMessage() !== '' ? $exception->getMessage() : 'Import processing failed.', 0, 1000),
            ])->save();
            $this->auditLogger->logTenant($company, AuditEvent::ImportFailed, $actor, $job, ['import_job_uuid' => $job->uuid, 'next_row' => $job->next_row]);
            $this->notifications->notifyCompany(
                $company,
                NotificationType::ImportFailed,
                'Import failed',
                $job->failure_message,
                'import.failed:'.$job->uuid,
                NotificationSeverity::Critical,
                route('catalog.imports.show', $job),
                ['import_job_uuid' => $job->uuid],
            );

            // Commit the failure report after the chunk savepoint rolled back;
            // rethrow outside this transaction so the report is not rolled back.
            $failure = $exception;

            return $job;
        }

        $pending = ImportRecord::query()->forCompany($company)
            ->where('import_job_id', $job->getKey())
            ->where('status', ImportRecordStatus::Valid->value)
            ->orderBy('row_number')
            ->first();
        $completed = $pending === null;
        $job->forceFill([
            'status' => $completed ? ImportJobStatus::Completed : ImportJobStatus::Processing,
            'next_row' => $completed ? $job->total_rows + 2 : $pending->row_number,
            'processed_rows' => $job->processed_rows + $counts['processed'],
            'created_rows' => $job->created_rows + $counts['created'],
            'updated_rows' => $job->updated_rows + $counts['updated'],
            'skipped_rows' => $job->skipped_rows + $counts['skipped'],
            'completed_at' => $completed ? now() : null,
        ])->save();

        $this->auditLogger->logTenant($company, $completed ? AuditEvent::ImportCompleted : AuditEvent::ImportChunkApplied, $actor, $job, [
            'import_job_uuid' => $job->uuid,
            'processed_rows' => $job->processed_rows,
            'next_row' => $job->next_row,
            'completed' => $completed,
        ]);

        if ($completed) {
            app(WebhookDeliveryService::class)->enqueue($company,
                WebhookEventType::ImportCompleted,
                ['import_job_uuid' => $job->uuid, 'processed_rows' => $job->processed_rows, 'created_rows' => $job->created_rows,
                    'updated_rows' => $job->updated_rows, 'skipped_rows' => $job->skipped_rows], 'import_job', $job->uuid);
            $this->notifications->notifyCompany(
                $company,
                NotificationType::ImportCompleted,
                'Import completed',
                sprintf('%d product rows processed.', $job->processed_rows),
                'import.completed:'.$job->uuid,
                NotificationSeverity::Success,
                route('catalog.imports.show', $job),
                ['import_job_uuid' => $job->uuid, 'processed_rows' => $job->processed_rows],
            );
        }

        return $job->refresh();
    }

    /** @return array{processed: int, created: int, updated: int, skipped: int} */
    private function applyChunk(User $actor, Company $company, ImportJob $job): array
    {
        return DB::transaction(function () use ($actor, $company, $job): array {
            $records = ImportRecord::query()->forCompany($company)
                ->where('import_job_id', $job->getKey())
                ->where('status', ImportRecordStatus::Valid->value)
                ->orderBy('row_number')
                ->limit($job->chunk_size)
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                try {
                    // Each row is its own atomic unit: the nested transaction
                    // creates a savepoint inside the chunk transaction, so a
                    // failing row rolls back every write it performed and
                    // never leaves a partially modified product behind.
                    DB::transaction(function () use ($actor, $company, $record): void {
                        $product = $record->operation === 'update'
                            ? $this->update($actor, $company, $record)
                            : $this->createProduct->execute(
                                $actor,
                                $company,
                                $record->mapped_data,
                                $this->primaryCategoryUuid($record->mapped_data),
                                imported: true,
                                defaultVariantIdentifiers: array_intersect_key($record->mapped_data, array_flip(VariantIdentifierNormalizer::FIELDS)),
                            );
                        if ($record->operation === 'update') {
                            $identifiers = array_intersect_key($record->mapped_data, array_flip(VariantIdentifierNormalizer::FIELDS));
                            if ($identifiers !== []) {
                                $variant = ProductVariant::query()->forCompany($company)
                                    ->where('product_id', $product->getKey())->whereKey($product->default_variant_id)->firstOrFail();
                                app(UpdateProductVariantAction::class)->execute($actor, $company, $product, $variant, $identifiers, imported: true);
                            }
                        }
                        $this->applyAttributes($actor, $company, $product, $record->mapped_data);
                        $record->forceFill([
                            'status' => ImportRecordStatus::Applied,
                            'target_product_id' => $product->getKey(),
                            'applied_at' => now(),
                        ])->save();
                    });
                } catch (Throwable $exception) {
                    if ($exception instanceof CommercialReadOnlyException) {
                        throw $exception;
                    }
                    if ($this->databaseFailureKind($exception) !== null) {
                        throw $exception;
                    }
                    report($exception);
                    // A database rollback does not restore the model's in-memory
                    // attributes, including references to rolled-back products.
                    $record->refresh();
                    $record->forceFill(['status' => ImportRecordStatus::Failed])->save();
                    ImportError::query()->create([
                        'company_id' => $company->getKey(),
                        'import_job_id' => $job->getKey(),
                        'import_record_id' => $record->getKey(),
                        'row_number' => $record->row_number,
                        'field' => null,
                        'code' => 'apply_failed',
                        'message' => mb_substr($exception->getMessage() !== '' ? $exception->getMessage() : 'The row could not be applied.', 0, 1000),
                    ]);
                }
            }

            // The caller holds the job row lock. Only rows claimed in this
            // transaction can contribute, and rollback discards this delta.
            return [
                'processed' => $records->count(),
                'created' => $records->filter(fn ($record) => $record->status === ImportRecordStatus::Applied && $record->operation === 'create')->count(),
                'updated' => $records->filter(fn ($record) => $record->status === ImportRecordStatus::Applied && $record->operation === 'update')->count(),
                'skipped' => $records->filter(fn ($record) => in_array($record->status, [ImportRecordStatus::Failed, ImportRecordStatus::Skipped], true))->count(),
            ];
        });
    }

    private function databaseFailureKind(Throwable $exception): ?string
    {
        $retryTransaction = false;
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof DeadlockException) {
                $retryTransaction = true;
            }
            if ($cause instanceof PDOException) {
                $state = (string) ($cause->errorInfo[0] ?? $cause->getCode());
                $driverCode = (int) ($cause->errorInfo[1] ?? 0);
                if (str_starts_with($state, '08') || in_array($driverCode, [2006, 2013], true)) {
                    return 'connection_loss';
                }
                if ($state === '40001' || in_array($driverCode, [1205, 1213, 1412], true)) {
                    $retryTransaction = true;
                }
            }
        }

        return $retryTransaction ? 'transaction_retry' : null;
    }

    private function update(User $actor, Company $company, ImportRecord $record): Product
    {
        $product = Product::query()->forCompany($company)->lockForUpdate()->find($record->target_product_id);
        if (! $product instanceof Product) {
            throw ValidationException::withMessages(['row' => 'The product selected during preview no longer exists.']);
        }

        $product->loadMissing([
            'primaryCategory' => fn ($query) => $query->forCompany($company)->sharedLock(),
            'categories' => fn ($query) => $query->forCompany($company)->orderBy('categories.id')->sharedLock(),
        ]);
        $additional = $product->categories
            ->reject(fn ($category): bool => $category->is($product->primaryCategory))
            ->pluck('uuid')->all();
        $primaryCategory = array_key_exists('primary_category_uuid', $record->mapped_data)
            ? $this->primaryCategoryUuid($record->mapped_data)
            : $product->primaryCategory?->uuid;

        return $this->updateProduct->execute($actor, $company, $product, $record->mapped_data, $primaryCategory, $additional, syncCategories: true);
    }

    /** @param array<string, mixed> $data */
    private function applyAttributes(User $actor, Company $company, Product $product, array $data): void
    {
        $imported = $data['attributes'] ?? null;
        if (! is_array($imported)) {
            return;
        }

        $payload = [];
        foreach ($imported as $definitionUuid => $value) {
            if (is_string($definitionUuid)) {
                $payload[$definitionUuid] = $value;
            }
        }
        $this->syncAttributes->mergeForImport($actor, $company, $product, $payload);
    }

    /** @param array<string, mixed> $data */
    private function primaryCategoryUuid(array $data): ?string
    {
        $uuid = $data['primary_category_uuid'] ?? null;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    private function authorize(User $actor, Company $company, ImportMode $mode): void
    {
        if ($mode !== ImportMode::Update) {
            $this->authorizer->authorizeCatalogWrite($actor, $company, CompanyPermission::CatalogCreate);
        }
        if ($mode !== ImportMode::Create) {
            $this->authorizer->authorizeCatalogWrite($actor, $company, CompanyPermission::CatalogUpdate);
        }
    }

    private function assertJob(Company $company, ImportJob $job): void
    {
        if ((int) $job->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }
}
