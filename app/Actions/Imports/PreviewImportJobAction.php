<?php

namespace App\Actions\Imports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyPermission;
use App\Enums\Imports\ImportJobStatus;
use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportNumberFormat;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\Imports\ImportError;
use App\Models\Imports\ImportJob;
use App\Models\Imports\ImportRecord;
use App\Models\User;
use App\Services\Imports\ImportFileReader;
use App\Services\Imports\ProductImportRowPlanner;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

class PreviewImportJobAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly ImportFileReader $reader,
        private readonly ProductImportRowPlanner $planner,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array<string, string> $mapping */
    public function execute(User $actor, Company $company, ImportJob $job, array $mapping, ImportNumberFormat $numberFormat = ImportNumberFormat::Dot): ImportJob
    {
        $this->assertJob($company, $job);
        $this->authorizer->authorize($actor, $company, $job->mode === ImportMode::Update ? CompanyPermission::CatalogUpdate : CompanyPermission::CatalogCreate);
        if (! in_array($job->status, [ImportJobStatus::Uploaded, ImportJobStatus::Previewed], true)) {
            throw ValidationException::withMessages(['job' => 'Only uploaded or previewed jobs can be previewed.']);
        }

        $path = Storage::disk((string) config('imports.disk'))->path($job->source_path);
        if (! is_file($path) || ! hash_equals($job->source_sha256, hash_file('sha256', $path))) {
            throw ValidationException::withMessages(['file' => 'The stored import source is missing or has changed.']);
        }

        $headers = $this->reader->headers($path, $job->source_format);
        $mapping = $this->validateMapping($company, $headers, $mapping);
        $maximum = (int) config('imports.max_rows');
        $seenSlugs = [];
        $seenIdentifiers = [];
        $hash = hash_init('sha256');
        $total = 0;
        $valid = 0;
        $invalid = 0;

        DB::transaction(function () use ($actor, $job, $company, $path, $headers, $mapping, $numberFormat, $maximum, &$seenSlugs, &$seenIdentifiers, $hash, &$total, &$valid, &$invalid): void {
            $this->authorizer->authorize($actor, $company, $job->mode === ImportMode::Update ? CompanyPermission::CatalogUpdate : CompanyPermission::CatalogCreate);
            $job = ImportJob::query()->forCompany($company)->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($job->status, [ImportJobStatus::Uploaded, ImportJobStatus::Previewed], true)) {
                throw ValidationException::withMessages(['job' => 'Only uploaded or previewed jobs can be previewed.']);
            }
            ImportError::query()->forCompany($company)->where('import_job_id', $job->getKey())->delete();
            ImportRecord::query()->forCompany($company)->where('import_job_id', $job->getKey())->delete();

            $rows = LazyCollection::make(fn () => $this->reader->rows($path, $job->source_format));
            foreach ($rows->chunk(500) as $batch) {
                $sources = $batch->all();
                $plans = $this->planner->planBatch($company, $sources, $mapping, $job->mode, $numberFormat);
                $records = [];
                $batchErrors = [];
                $timestamp = now();
                foreach ($sources as $rowNumber => $source) {
                    $total++;
                    if ($total > $maximum) {
                        throw ValidationException::withMessages(['file' => "The import contains more than {$maximum} data rows."]);
                    }

                    $plan = $plans[$rowNumber];
                    $errors = $plan->errors;
                    $slug = (string) ($plan->mapped['slug'] ?? '');
                    if ($slug !== '' && isset($seenSlugs[$slug])) {
                        $errors[] = ['field' => 'slug', 'code' => 'duplicate_row', 'message' => "The slug is already used by row {$seenSlugs[$slug]}."];
                    } elseif ($slug !== '') {
                        $seenSlugs[$slug] = $rowNumber;
                    }

                    foreach (['sku', 'gtin'] as $field) {
                        $value = $plan->mapped[$field] ?? null;
                        if ($value === null || $value === '') {
                            continue;
                        }
                        if ($field === 'sku') {
                            try {
                                $value = app(CatalogIdentifierNormalizer::class)->normalizeSku($value);
                            } catch (\InvalidArgumentException) {
                                continue;
                            }
                        }
                        if (isset($seenIdentifiers[$field][$value])) {
                            $errors[] = ['field' => $field, 'code' => 'duplicate_row', 'message' => "The {$field} is already used by row {$seenIdentifiers[$field][$value]}."];
                        } else {
                            $seenIdentifiers[$field][$value] = $rowNumber;
                        }
                    }

                    $fingerprint = hash('sha256', json_encode([$source, $plan->mapped, $plan->operation], JSON_THROW_ON_ERROR));
                    hash_update($hash, $rowNumber.':'.$fingerprint.';');
                    $records[] = (new ImportRecord([
                        'company_id' => $company->getKey(),
                        'import_job_id' => $job->getKey(),
                        'row_number' => $rowNumber,
                        'row_fingerprint' => $fingerprint,
                        'source_data' => $source,
                        'mapped_data' => $plan->mapped,
                        'normalizations' => $plan->normalizations,
                        'status' => $errors === [] ? 'valid' : 'invalid',
                        'operation' => $plan->operation,
                        'target_product_id' => $plan->targetProductId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]))->getAttributes();

                    if ($errors === []) {
                        $valid++;
                    } else {
                        $invalid++;
                        foreach ($errors as $error) {
                            $batchErrors[] = [
                                'company_id' => $company->getKey(),
                                'import_job_id' => $job->getKey(),
                                'row_number' => $rowNumber,
                                ...$error,
                                'created_at' => $timestamp,
                                'updated_at' => $timestamp,
                            ];
                        }
                    }
                }
                // Plan records have no model observers; the preview audit is
                // the domain event. Persist bounded batches in the same job
                // transaction so a failed preview never replaces a valid plan.
                foreach (array_chunk($records, 100) as $recordBatch) {
                    ImportRecord::query()->insert($recordBatch);
                }
                if ($batchErrors !== []) {
                    $ids = ImportRecord::query()->forCompany($company)->where('import_job_id', $job->getKey())
                        ->whereIn('row_number', array_keys($sources))->pluck('id', 'row_number');
                    foreach ($batchErrors as &$error) {
                        $error['import_record_id'] = $ids[$error['row_number']];
                    }
                    unset($error);
                    foreach (array_chunk($batchErrors, 500) as $errors) {
                        ImportError::query()->insert($errors);
                    }
                }
            }

            $planFingerprint = hash('sha256', $job->source_sha256.'|'.json_encode([$mapping, $numberFormat->value], JSON_THROW_ON_ERROR).'|'.hash_final($hash));
            $job->forceFill([
                'headers' => $headers,
                'mapping' => $mapping,
                'number_format' => $numberFormat,
                'plan_fingerprint' => $planFingerprint,
                'status' => ImportJobStatus::Previewed,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'invalid_rows' => $invalid,
                'processed_rows' => 0,
                'created_rows' => 0,
                'updated_rows' => 0,
                'skipped_rows' => 0,
                'next_row' => 2,
                'previewed_at' => now(),
                'started_at' => null,
                'completed_at' => null,
                'failure_message' => null,
            ])->save();
            $this->auditLogger->logTenant($company, AuditEvent::ImportPreviewed, $actor, $job, [
                'import_job_uuid' => $job->uuid,
                'source_filename' => $job->source_filename,
                'total_rows' => $total,
                'valid_rows' => $valid,
                'invalid_rows' => $invalid,
            ]);
        });

        return $job->refresh()->load(['records' => fn ($query) => $query->orderBy('row_number')->limit((int) config('imports.preview_rows')), 'records.errors']);
    }

    /** @param list<string> $headers @param array<string, string> $mapping @return array<string, string> */
    private function validateMapping(Company $company, array $headers, array $mapping): array
    {
        $attributeFields = AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->pluck('code')->map(fn (string $code): string => 'attribute:'.$code)->all();
        $allowed = [...ProductImportRowPlanner::FIELDS, 'primary_category', ...$attributeFields];
        foreach (array_diff(array_keys($mapping), $allowed) as $field) {
            throw ValidationException::withMessages(["mapping.{$field}" => 'The mapped target field is not supported or is unavailable.']);
        }
        if (! isset($mapping['name']) || ! in_array($mapping['name'], $headers, true)) {
            throw ValidationException::withMessages(['mapping.name' => 'Map a source column to the required product name field.']);
        }
        foreach ($mapping as $field => $header) {
            if (! is_string($header) || ! in_array($header, $headers, true)) {
                throw ValidationException::withMessages(["mapping.{$field}" => 'The mapped source column does not exist.']);
            }
        }

        return $mapping;
    }

    private function assertJob(Company $company, ImportJob $job): void
    {
        if ((int) $job->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }
}
