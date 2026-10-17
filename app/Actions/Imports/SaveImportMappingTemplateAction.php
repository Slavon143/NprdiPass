<?php

namespace App\Actions\Imports;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\Imports\ImportNumberFormat;
use App\Models\Company;
use App\Models\Imports\ImportMappingTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveImportMappingTemplateAction
{
    public function __construct(private readonly CompanyAuthorizer $authorizer) {}

    /** @param array<string, string|null> $mapping */
    public function execute(User $actor, Company $company, string $name, array $mapping, ImportNumberFormat $numberFormat): ImportMappingTemplate
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('validation.required', ['attribute' => __('Template name')])]);
        }

        return DB::transaction(function () use ($actor, $company, $name, $mapping, $numberFormat): ImportMappingTemplate {
            $this->authorizer->authorize($actor, $company, CompanyPermission::CatalogUpdate);
            $template = ImportMappingTemplate::query()->forCompany($company)
                ->where('name_normalized', Str::lower($name))->lockForUpdate()->first() ?? new ImportMappingTemplate;
            $template->forceFill([
                'company_id' => $company->getKey(), 'name_normalized' => Str::lower($name),
                'name' => $name, 'entity_type' => 'product', 'mapping' => array_filter($mapping, fn ($value) => is_string($value) && $value !== ''),
                'defaults' => ['number_format' => $numberFormat->value],
                'created_by' => $template->exists ? $template->created_by : $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->save();

            return $template;
        });
    }
}
