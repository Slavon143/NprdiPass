<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Actions\Imports\SaveImportMappingTemplateAction;
use App\Actions\Imports\StartImportJobAction;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyPermission;
use App\Enums\Imports\ImportJobStatus;
use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportNumberFormat;
use App\Http\Controllers\Controller;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\Imports\ImportError;
use App\Models\Imports\ImportJob;
use App\Models\Imports\ImportMappingTemplate;
use App\Models\User;
use App\Services\Imports\ProductExportService;
use App\Services\Imports\ProductImportRowPlanner;
use App\Services\Jobs\JobStatusTracker;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::CatalogView->value, $company);

        return view('catalog.imports.index', [
            'jobs' => ImportJob::query()->forCompany($company)->latest()->paginate(20),
            'templates' => ImportMappingTemplate::query()->forCompany($company)->orderBy('name')->get(),
            'fields' => $this->mappingFields($company),
            'canWrite' => $request->user()?->can(CompanyPermission::CatalogCreate->value, $company) === true,
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany, CreateImportJobAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.config('imports.max_upload_kilobytes')],
            'mode' => ['required', 'in:create,update,upsert'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
            'chunk_size' => ['nullable', 'integer', 'between:1,1000'],
        ]);
        $file = $validated['file'];
        abort_unless($file instanceof UploadedFile, 422);
        $job = $action->execute($this->actor($request), $currentCompany->require(), $file, ImportMode::from($validated['mode']), $validated['idempotency_key'] ?? null, $validated['chunk_size'] ?? null);

        return redirect()->route('catalog.imports.show', $job)->with('success', __('Import uploaded. Map columns and run preview before applying changes.'));
    }

    public function show(Request $request, CurrentCompany $currentCompany, string $importJob): View
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::CatalogView->value, $company);
        $job = $this->job($company, $importJob);
        $job->load(['records' => fn ($query) => $query->with('errors')->orderBy('row_number')->limit((int) config('imports.preview_rows'))]);

        return view('catalog.imports.show', [
            'job' => $job,
            'fields' => $this->mappingFields($company),
            'templates' => ImportMappingTemplate::query()->forCompany($company)->orderBy('name')->get(),
            'canWrite' => $request->user()?->can(CompanyPermission::CatalogCreate->value, $company) === true,
        ]);
    }

    public function preview(Request $request, CurrentCompany $currentCompany, PreviewImportJobAction $action, string $importJob): RedirectResponse
    {
        $validated = $request->validate(['mapping' => ['required', 'array'], 'mapping.*' => ['nullable', 'string', 'max:255'], 'number_format' => ['sometimes', 'required', 'in:dot,comma']]);
        $mapping = array_filter($validated['mapping'], fn (mixed $value): bool => is_string($value) && $value !== '');
        $action->execute($this->actor($request), $currentCompany->require(), $this->job($currentCompany->require(), $importJob), $mapping, ImportNumberFormat::from($validated['number_format'] ?? 'dot'));

        return back()->with('success', __('Preview generated. Review row errors before applying the import.'));
    }

    public function apply(Request $request, CurrentCompany $currentCompany, ApplyImportJobAction $action, StartImportJobAction $start, JobStatusTracker $tracker, string $importJob): RedirectResponse
    {
        $company = $currentCompany->require();
        $actor = $this->actor($request);
        $job = $this->job($company, $importJob);
        [$job, $jobStatus, $queued] = $start->execute($actor, $company, $job);
        if ($queued) {

            return back()->with('success', __('Large import queued. Progress will update on this page.'));
        }

        $tracker->markRunning($jobStatus);
        try {
            do {
                $job = $action->execute($actor, $company, $job);
                $tracker->markProgress($jobStatus, $job->processed_rows, $job->total_rows);
            } while ($job->status === ImportJobStatus::Processing);
            $tracker->markSucceeded($jobStatus, ['import_job_uuid' => $job->uuid, 'status' => $job->status->value]);
        } catch (Throwable $exception) {
            $tracker->markFailed($jobStatus, 'import_failed', $exception->getMessage());
            throw $exception;
        }

        return back()->with('success', $job->completed_at !== null ? __('Import completed.') : __('Import stopped with errors. Review the recovery details.'));
    }

    public function cancel(Request $request, CurrentCompany $currentCompany, StartImportJobAction $start, string $importJob): RedirectResponse
    {
        $job = $this->job($currentCompany->require(), $importJob);
        $start->cancel($this->actor($request), $currentCompany->require(), $job);

        return back()->with('success', __('Import cancellation requested.'));
    }

    public function errors(CurrentCompany $currentCompany, string $importJob): StreamedResponse
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::CatalogView->value, $company);
        $job = $this->job($company, $importJob);

        return response()->streamDownload(function () use ($job, $company): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['row', 'field', 'code', 'message'], ',', '"', '');
            ImportError::query()->forCompany($company)->where('import_job_id', $job->getKey())->orderBy('row_number')->chunk(500, function ($errors) use ($out): void {
                foreach ($errors as $error) {
                    fputcsv($out, [$error->row_number, $error->field, $error->code, $error->message], ',', '"', '');
                }
            });
            fclose($out);
        }, "import-{$job->uuid}-errors.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function storeTemplate(Request $request, CurrentCompany $currentCompany, SaveImportMappingTemplateAction $action): RedirectResponse
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::CatalogUpdate->value, $company);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'mapping' => ['required', 'array'], 'mapping.name' => ['required', 'string'], 'mapping.*' => ['nullable', 'string', 'max:255'], 'number_format' => ['sometimes', 'required', 'in:dot,comma']]);
        $action->execute($this->actor($request), $company, $validated['name'], $validated['mapping'], ImportNumberFormat::from($validated['number_format'] ?? 'dot'));

        return back()->with('success', __('Mapping template saved.'));
    }

    public function export(Request $request, CurrentCompany $currentCompany, ProductExportService $exporter, string $format): StreamedResponse
    {
        return $exporter->download($this->actor($request), $currentCompany->require(), $format);
    }

    private function job(Company $company, string $uuid): ImportJob
    {
        return ImportJob::query()->forCompany($company)->where('uuid', $uuid)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @return list<string> */
    private function mappingFields(Company $company): array
    {
        $attributes = AttributeDefinition::query()->forCompany($company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->ordered()->pluck('code')->map(fn (string $code): string => 'attribute:'.$code)->all();

        return [...ProductImportRowPlanner::FIELDS, 'primary_category', ...$attributes];
    }
}
