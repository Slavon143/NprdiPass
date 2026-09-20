<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Enums\Imports\ImportMode;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Imports\ImportError;
use App\Models\Imports\ImportJob;
use App\Models\Imports\ImportMappingTemplate;
use App\Models\Imports\ImportRecord;
use App\Models\User;
use App\Services\Imports\ProductExportService;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function templates(TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $templates = ImportMappingTemplate::query()->forCompany($currentCompany->require())->orderBy('name')->get();

        return $response->success($templates->map->only(['uuid', 'name', 'entity_type', 'mapping', 'defaults'])->all());
    }

    public function storeTemplate(Request $request, TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'mapping' => ['required', 'array'], 'mapping.name' => ['required', 'string'], 'mapping.*' => ['nullable', 'string', 'max:255']]);
        $company = $currentCompany->require();
        $actor = $this->actor($request);
        $template = ImportMappingTemplate::query()->updateOrCreate(
            ['company_id' => $company->getKey(), 'name_normalized' => Str::lower(trim($validated['name']))],
            ['name' => trim($validated['name']), 'entity_type' => 'product', 'mapping' => array_filter($validated['mapping']), 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()],
        );

        return $response->created($template->only(['uuid', 'name', 'entity_type', 'mapping', 'defaults']));
    }

    public function index(TokenCurrentCompany $currentCompany, ApiResponse $response): JsonResponse
    {
        $jobs = ImportJob::query()->forCompany($currentCompany->require())->latest()->paginate(50);

        return $response->paginated($jobs->getCollection()->map(fn (ImportJob $job): array => $this->resource($job))->all(), $jobs);
    }

    public function store(Request $request, TokenCurrentCompany $currentCompany, CreateImportJobAction $action, ApiResponse $response): JsonResponse
    {
        $validated = $request->validate(['file' => ['required', 'file', 'max:'.config('imports.max_upload_kilobytes')], 'mode' => ['required', 'in:create,update,upsert'], 'idempotency_key' => ['nullable', 'string', 'max:191'], 'chunk_size' => ['nullable', 'integer', 'between:1,1000']]);
        $file = $validated['file'];
        abort_unless($file instanceof UploadedFile, 422);
        $job = $action->execute($this->actor($request), $currentCompany->require(), $file, ImportMode::from($validated['mode']), $validated['idempotency_key'] ?? null, $validated['chunk_size'] ?? null);

        return $response->created($this->resource($job));
    }

    public function show(TokenCurrentCompany $currentCompany, ApiResponse $response, string $importJob): JsonResponse
    {
        $job = $this->job($currentCompany->require(), $importJob);
        $job->load(['records' => fn ($query) => $query->with('errors')->orderBy('row_number')->limit((int) config('imports.preview_rows'))]);

        return $response->success($this->resource($job, true));
    }

    public function preview(Request $request, TokenCurrentCompany $currentCompany, PreviewImportJobAction $action, ApiResponse $response, string $importJob): JsonResponse
    {
        $validated = $request->validate(['mapping' => ['required', 'array'], 'mapping.name' => ['required', 'string'], 'mapping.*' => ['nullable', 'string', 'max:255']]);
        $mapping = array_filter($validated['mapping'], fn (mixed $value): bool => is_string($value) && $value !== '');
        $job = $action->execute($this->actor($request), $currentCompany->require(), $this->job($currentCompany->require(), $importJob), $mapping);

        return $response->success($this->resource($job, true));
    }

    public function apply(Request $request, TokenCurrentCompany $currentCompany, ApplyImportJobAction $action, ApiResponse $response, string $importJob): JsonResponse
    {
        $validated = $request->validate(['max_chunks' => ['nullable', 'integer', 'between:1,100']]);
        $job = $action->execute($this->actor($request), $currentCompany->require(), $this->job($currentCompany->require(), $importJob), (int) ($validated['max_chunks'] ?? 1));

        return $response->success($this->resource($job));
    }

    public function errors(TokenCurrentCompany $currentCompany, string $importJob): StreamedResponse
    {
        $company = $currentCompany->require();
        $job = $this->job($company, $importJob);

        return response()->streamDownload(function () use ($company, $job): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fputcsv($output, ['row', 'field', 'code', 'message'], ',', '"', '');
            ImportError::query()->forCompany($company)->where('import_job_id', $job->getKey())->orderBy('row_number')->chunk(500, function ($errors) use ($output): void {
                foreach ($errors as $error) {
                    fputcsv($output, [$error->row_number, $error->field, $error->code, $error->message], ',', '"', '');
                }
            });
            fclose($output);
        }, "import-{$job->uuid}-errors.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(Request $request, TokenCurrentCompany $currentCompany, ProductExportService $exporter, string $format): StreamedResponse
    {
        return $exporter->download($this->actor($request), $currentCompany->require(), $format);
    }

    private function job(Company $company, string $uuid): ImportJob
    {
        return ImportJob::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function resource(ImportJob $job, bool $includeRows = false): array
    {
        $data = [
            'uuid' => $job->uuid, 'status' => $job->status->value, 'mode' => $job->mode->value,
            'source_filename' => $job->source_filename, 'headers' => $job->headers, 'mapping' => $job->mapping,
            'total_rows' => $job->total_rows, 'valid_rows' => $job->valid_rows, 'invalid_rows' => $job->invalid_rows,
            'processed_rows' => $job->processed_rows, 'created_rows' => $job->created_rows, 'updated_rows' => $job->updated_rows,
            'skipped_rows' => $job->skipped_rows, 'next_row' => $job->next_row, 'chunk_size' => $job->chunk_size,
            'created_at' => $job->created_at?->toIso8601String(), 'completed_at' => $job->completed_at?->toIso8601String(),
        ];
        if ($includeRows && $job->relationLoaded('records')) {
            $data['records'] = $job->records->map(fn (ImportRecord $record): array => ['row_number' => $record->row_number, 'status' => $record->status->value, 'operation' => $record->operation, 'mapped_data' => $record->mapped_data, 'errors' => $record->errors->map->only(['field', 'code', 'message'])->all()])->all();
        }

        return $data;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
