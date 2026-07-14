<?php

namespace App\Actions\Catalog\Attributes;

use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateAttributeDefinitionAction extends AttributeAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, Company $company, array $data): AttributeDefinition
    {
        $company = $this->authorize($actor, $company);

        try {
            return DB::transaction(function () use ($actor, $company, $data): AttributeDefinition {
                $company = $this->authorize($actor, $company);
                $count = AttributeDefinition::query()->forCompany($company)->lockForUpdate()->count();

                if ($count >= self::MAX_DEFINITIONS) {
                    throw AttributeOperationException::invalid('attribute', 'The maximum number of attributes has been reached.');
                }

                $definition = new AttributeDefinition;
                $definition->forceFill([
                    'company_id' => $company->getKey(),
                    ...$this->definitionData($data),
                    'status' => AttributeDefinitionStatus::Active,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ])->save();

                $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeCreated, $actor, $definition, [
                    'attribute_uuid' => $definition->uuid,
                    'code' => $definition->code,
                    'data_type' => $definition->type->value,
                    'scope' => $definition->scope->value,
                ]);

                return $definition->refresh();
            });
        } catch (QueryException $exception) {
            throw $this->mapDuplicate($exception) ?? $exception;
        }
    }
}
