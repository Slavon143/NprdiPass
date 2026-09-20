<?php

namespace App\Actions\Imports;

use App\Actions\Catalog\Attributes\SyncProductAttributeValuesAction;
use App\Actions\Catalog\Products\CreateProductAction;
use App\Actions\Catalog\Products\UpdateProductAction;
use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyPermission;
use App\Enums\Imports\ImportJobStatus;
use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportRecordStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Company;
use App\Models\Imports\ImportError;
use App\Models\Imports\ImportJob;
use App\Models\Imports\ImportRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplyImportJobAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly CreateProductAction $createProduct,
        private readonly UpdateProductAction $updateProduct,
        private readonly SyncProductAttributeValuesAction $syncAttributes,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(User $actor, Company $company, ImportJob $job, int $maxChunks = 1): ImportJob
    {
        $this->assertJob($company, $job);
        $this->authorize($actor, $company, $job->mode);
        if ($job->status === ImportJobStatus::Completed) {
            return $job->refresh();
        }
        if (! in_array($job->status, [ImportJobStatus::Previewed, ImportJobStatus::Processing, ImportJobStatus::Failed], true)) {
            throw ValidationException::withMessages(['job' => 'Preview the import before applying it.']);
        }

        $path = Storage::disk((string) config('imports.disk'))->path($job->source_path);
        if (! is_file($path) || ! hash_equals($job->source_sha256, hash_file('sha256', $path))) {
            throw ValidationException::withMessages(['file' => 'The previewed import source is missing or has changed.']);
        }

        $job->forceFill(['status' => ImportJobStatus::Processing, 'started_at' => $job->started_at ?? now(), 'failure_message' => null])->save();
        try {
            $chunks = max(1, min(100, $maxChunks));
            for ($chunk = 0; $chunk < $chunks; $chunk++) {
                $processed = $this->applyChunk($actor, $company, $job);
                if ($processed === 0) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            $job->forceFill([
                'status' => ImportJobStatus::Failed,
                'failure_message' => mb_substr($exception->getMessage() !== '' ? $exception->getMessage() : 'Import processing failed.', 0, 1000),
            ])->save();
            $this->auditLogger->logTenant($company, AuditEvent::ImportFailed, $actor, $job, ['import_job_uuid' => $job->uuid, 'next_row' => $job->next_row]);

            throw $exception;
        }

        $pending = ImportRecord::query()->forCompany($company)
            ->where('import_job_id', $job->getKey())
            ->where('status', ImportRecordStatus::Valid->value)
            ->orderBy('row_number')
            ->first();
        $counts = ImportRecord::query()->forCompany($company)
            ->where('import_job_id', $job->getKey())
            ->selectRaw("SUM(status IN ('applied','failed','skipped')) AS processed")
            ->selectRaw("SUM(status = 'applied' AND operation = 'create') AS created_count")
            ->selectRaw("SUM(status = 'applied' AND operation = 'update') AS updated_count")
            ->selectRaw("SUM(status IN ('failed','skipped')) AS skipped_count")
            ->first();

        $completed = $pending === null;
        $job->forceFill([
            'status' => $completed ? ImportJobStatus::Completed : ImportJobStatus::Processing,
            'next_row' => $completed ? $job->total_rows + 2 : $pending->row_number,
            'processed_rows' => (int) ($counts?->getAttribute('processed') ?? 0),
            'created_rows' => (int) ($counts?->getAttribute('created_count') ?? 0),
            'updated_rows' => (int) ($counts?->getAttribute('updated_count') ?? 0),
            'skipped_rows' => (int) ($counts?->getAttribute('skipped_count') ?? 0),
            'completed_at' => $completed ? now() : null,
        ])->save();

        $this->auditLogger->logTenant($company, $completed ? AuditEvent::ImportCompleted : AuditEvent::ImportChunkApplied, $actor, $job, [
            'import_job_uuid' => $job->uuid,
            'processed_rows' => $job->processed_rows,
            'next_row' => $job->next_row,
            'completed' => $completed,
        ]);

        return $job->refresh();
    }

    private function applyChunk(User $actor, Company $company, ImportJob $job): int
    {
        return DB::transaction(function () use ($actor, $company, $job): int {
            $records = ImportRecord::query()->forCompany($company)
                ->where('import_job_id', $job->getKey())
                ->where('status', ImportRecordStatus::Valid->value)
                ->orderBy('row_number')
                ->limit($job->chunk_size)
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                try {
                    $product = $record->operation === 'update'
                        ? $this->update($actor, $company, $record)
                        : $this->createProduct->execute(
                            $actor,
                            $company,
                            $record->mapped_data,
                            $this->primaryCategoryUuid($record->mapped_data),
                        );
                    $this->applyAttributes($actor, $company, $product, $record->mapped_data);
                    $record->forceFill([
                        'status' => ImportRecordStatus::Applied,
                        'target_product_id' => $product->getKey(),
                        'applied_at' => now(),
                    ])->save();
                } catch (Throwable $exception) {
                    report($exception);
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

            return $records->count();
        });
    }

    private function update(User $actor, Company $company, ImportRecord $record): Product
    {
        $product = Product::query()->forCompany($company)->find($record->target_product_id);
        if (! $product instanceof Product) {
            throw ValidationException::withMessages(['row' => 'The product selected during preview no longer exists.']);
        }

        $product->loadMissing(['primaryCategory', 'categories']);
        $additional = $product->categories
            ->reject(fn ($category): bool => $category->is($product->primaryCategory))
            ->pluck('uuid')->all();
        $primaryCategory = array_key_exists('primary_category_uuid', $record->mapped_data)
            ? $this->primaryCategoryUuid($record->mapped_data)
            : $product->primaryCategory?->uuid;

        return $this->updateProduct->execute($actor, $company, $product, $record->mapped_data, $primaryCategory, $additional);
    }

    /** @param array<string, mixed> $data */
    private function applyAttributes(User $actor, Company $company, Product $product, array $data): void
    {
        $imported = $data['attributes'] ?? null;
        if (! is_array($imported)) {
            return;
        }

        $definitions = AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->get();
        $existing = ProductAttributeValue::query()->forCompany($company)
            ->where('product_id', $product->getKey())
            ->with('selectedOptions')->get()->keyBy('attribute_definition_id');
        $payload = [];
        foreach ($definitions as $definition) {
            $value = $existing->get($definition->getKey());
            if ($value instanceof ProductAttributeValue) {
                $payload[$definition->uuid] = match ($definition->type) {
                    AttributeDataType::Text => $value->value_text,
                    AttributeDataType::Integer => $value->value_integer,
                    AttributeDataType::Decimal => $value->value_decimal,
                    AttributeDataType::Boolean => $value->value_boolean ? '1' : '0',
                    AttributeDataType::Date => $value->value_date?->format('Y-m-d'),
                    AttributeDataType::Select => $value->value_option_id,
                    AttributeDataType::Multiselect => $value->selectedOptions->pluck('id')->all(),
                };
            }
        }
        foreach ($imported as $definitionUuid => $value) {
            if (is_string($definitionUuid)) {
                $payload[$definitionUuid] = $value;
            }
        }
        $this->syncAttributes->execute($actor, $company, $product, $payload);
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
            $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogCreate);
        }
        if ($mode !== ImportMode::Create) {
            $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogUpdate);
        }
    }

    private function assertJob(Company $company, ImportJob $job): void
    {
        if ((int) $job->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }
}
