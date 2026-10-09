<?php

namespace App\Actions\Imports;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Imports\ImportJobStatus;
use App\Enums\Imports\ImportMode;
use App\Models\Company;
use App\Models\Imports\ImportJob;
use App\Models\User;
use App\Services\Billing\CommercialUsageAdmission;
use App\Services\Billing\EntitlementService;
use App\Services\Imports\ImportFileReader;
use App\Services\Storage\FileWriteCompensation;
use App\Services\Storage\StorageQuotaAdmission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateImportJobAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly ImportFileReader $reader,
        private readonly AuditLogger $auditLogger,
        private readonly EntitlementService $entitlements,
    ) {}

    public function execute(User $actor, Company $company, UploadedFile $file, ImportMode $mode, ?string $idempotencyKey = null, ?int $chunkSize = null): ImportJob
    {
        $this->authorizer->authorize($actor, $company, $mode === ImportMode::Update ? CompanyPermission::CatalogUpdate : CompanyPermission::CatalogCreate);
        $this->entitlements->assertEnabled($company, 'imports.enabled');

        $extension = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => 'Only CSV and XLSX files are supported.']);
        }
        if ($file->getSize() === false || $file->getSize() > (int) config('imports.max_upload_kilobytes') * 1024) {
            throw ValidationException::withMessages(['file' => 'The import file exceeds the configured size limit.']);
        }

        $mime = (string) $file->getMimeType();
        $allowedMimes = $extension === 'xlsx'
            ? ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']
            : ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
        if (! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages(['file' => 'The file content type does not match a supported import format.']);
        }
        if ($extension === 'xlsx') {
            $this->reader->assertSafeXlsx($file->getRealPath());
        }
        $headers = $this->reader->headers($file->getRealPath(), $extension);
        if ($headers === []) {
            throw ValidationException::withMessages(['file' => 'The import file must contain a header row and at least one data row.']);
        }

        $sha = hash_file('sha256', $file->getRealPath());
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = ImportJob::query()->forCompany($company)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->source_sha256, $sha)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key was already used for a different file.']);
                }

                return $existing;
            }
        }

        $uuid = Str::uuid()->toString();
        $path = "companies/{$company->uuid}/jobs/{$uuid}/source.{$extension}";
        $quota = app(StorageQuotaAdmission::class);
        $reservation = $quota->reserve($company, (string) config('imports.disk'), $path, (int) $file->getSize(), $uuid);
        $cleanup = app(FileWriteCompensation::class)->track(
            fn (): bool => ImportJob::query()->forCompany($company)->where('uuid', $uuid)->sharedLock()->exists(),
            fn (): bool => $quota->compensate($company, $reservation, fn (): bool => Storage::disk((string) config('imports.disk'))->delete($path)),
            ['company_uuid' => $company->uuid, 'import_job_uuid' => $uuid, 'operation' => 'import_upload'],
        );

        try {
            if (Storage::disk((string) config('imports.disk'))->putFileAs(dirname($path), $file, basename($path)) !== $path) {
                throw new \RuntimeException('The import source could not be stored.');
            }
            $quota->written($company, $reservation);
            $result = DB::transaction(function () use ($uuid, $company, $actor, $mode, $extension, $file, $path, $mime, $sha, $headers, $idempotencyKey, $chunkSize): ImportJob {
                $this->authorizer->authorize($actor, $company, $mode === ImportMode::Update ? CompanyPermission::CatalogUpdate : CompanyPermission::CatalogCreate);
                if ($idempotencyKey !== null && $idempotencyKey !== '') {
                    $existing = ImportJob::query()->forCompany($company)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                    if ($existing !== null) {
                        if (! hash_equals($existing->source_sha256, $sha)) {
                            throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key was already used for a different file.']);
                        }

                        return $existing;
                    }
                }
                $job = ImportJob::query()->create([
                    'uuid' => $uuid,
                    'company_id' => $company->getKey(),
                    'requested_by' => $actor->getKey(),
                    'entity_type' => 'product',
                    'mode' => $mode,
                    'status' => ImportJobStatus::Uploaded,
                    'source_format' => $extension,
                    'source_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'source_path' => $path,
                    'source_mime' => $mime,
                    'source_size' => $file->getSize(),
                    'source_sha256' => $sha,
                    'headers' => $headers,
                    'idempotency_key' => $idempotencyKey === null || $idempotencyKey === '' ? null : mb_substr($idempotencyKey, 0, 191),
                    'chunk_size' => max(1, min(1000, $chunkSize ?? (int) config('imports.default_chunk_size'))),
                ]);

                app(CommercialUsageAdmission::class)->consume($company, 'imports', $uuid);

                $this->auditLogger->logTenant($company, AuditEvent::ImportUploaded, $actor, $job, [
                    'import_job_uuid' => $job->uuid,
                    'source_filename' => $job->source_filename,
                    'source_format' => $job->source_format,
                    'source_size' => $job->source_size,
                ]);

                return $job;
            }, 5);
            if ($result->uuid !== $uuid) {
                $cleanup();
            }

            return $result;
        } catch (Throwable $exception) {
            $cleanup();
            if ($exception instanceof AuthorizationException) {
                throw $exception;
            }
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = ImportJob::query()->forCompany($company)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null && hash_equals($existing->source_sha256, $sha)) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }
}
