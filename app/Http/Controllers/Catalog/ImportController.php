<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Imports\ApplyImportJobAction;
use App\Actions\Imports\CreateImportJobAction;
use App\Actions\Imports\PreviewImportJobAction;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyPermission;
use App\Enums\Imports\ImportMode;
use App\Http\Controllers\Controller;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\Imports\ImportError;
use App\Models\Imports\ImportJob;
use App\Models\Imports\ImportMappingTemplate;
use App\Models\User;
use App\Services\Imports\ProductExportService;
use App\Services\Imports\ProductImportRowPlanner;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        return redirect()->route('catalog.imports.show', $job)->with('success', 'Import uploaded. Map columns and run preview before applying changes.');
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
        $validated = $request->validate(['mapping' => ['required', 'array'], 'mapping.*' => ['nullable', 'string', 'max:255']]);
        $mapping = array_filter($validated['mapping'], fn (mixed $value): bool => is_string($value) && $value !== '');
        $action->execute($this->actor($request), $currentCompany->require(), $this->job($currentCompany->require(), $importJob), $mapping);

        return back()->with('success', 'Preview generated. Review row errors before applying the import.');
    }

    public function apply(Request $request, CurrentCompany $currentCompany, ApplyImportJobAction $action, string $importJob): RedirectResponse
    {
        $validated = $request->validate(['max_chunks' => ['nullable', 'integer', 'between:1,100']]);
        $job = $action->execute($this->actor($request), $currentCompany->require(), $this->job($currentCompany->require(), $importJob), (int) ($validated['max_chunks'] ?? 1));

        return back()->with('success', $job->completed_at !== null ? 'Import completed.' : 'Chunk applied. Continue to resume from the saved cursor.');
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

    public function storeTemplate(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = $currentCompany->require();
        $this->authorize(CompanyPermission::CatalogUpdate->value, $company);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'mapping' => ['required', 'array'], 'mapping.name' => ['required', 'string'], 'mapping.*' => ['nullable', 'string', 'max:255']]);
        ImportMappingTemplate::query()->updateOrCreate(
            ['company_id' => $company->getKey(), 'name_normalized' => Str::lower(trim($validated['name']))],
            ['name' => trim($validated['name']), 'entity_type' => 'product', 'mapping' => array_filter($validated['mapping']), 'created_by' => $this->actor($request)->getKey(), 'updated_by' => $this->actor($request)->getKey()],
        );

        return back()->with('success', 'Mapping template saved.');
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
