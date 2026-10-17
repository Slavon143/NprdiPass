<?php

namespace App\Actions\Imports;

use App\Authorization\ApiTokenMutationAuthorizer;
use App\Enums\ApiTokenAbility;
use App\Enums\Imports\ImportNumberFormat;
use App\Models\Company;
use App\Models\Imports\ImportMappingTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveApiImportMappingTemplate
{
    public function __construct(private readonly ApiTokenMutationAuthorizer $authorizer) {}

    /** @param array{name: string, mapping: array<string, mixed>, number_format?: string} $data */
    public function execute(User $actor, Company $company, array $data): ImportMappingTemplate
    {
        return DB::transaction(function () use ($actor, $company, $data): ImportMappingTemplate {
            $this->authorizer->authorize($actor, $company, ApiTokenAbility::ImportsWrite->value);
            $name = trim($data['name']);
            $normalized = Str::lower($name);
            $template = ImportMappingTemplate::query()->forCompany($company)
                ->where('name_normalized', $normalized)->lockForUpdate()->first() ?? new ImportMappingTemplate;
            $template->forceFill([
                'company_id' => $company->getKey(), 'name_normalized' => $normalized,
                'name' => $name, 'entity_type' => 'product', 'mapping' => array_filter($data['mapping']),
                'defaults' => ['number_format' => ImportNumberFormat::from($data['number_format'] ?? 'dot')->value],
                'created_by' => $template->exists ? $template->created_by : $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->save();

            return $template;
        });
    }
}
