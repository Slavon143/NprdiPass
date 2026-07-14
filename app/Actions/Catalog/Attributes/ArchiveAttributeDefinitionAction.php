<?php

namespace App\Actions\Catalog\Attributes;

use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveAttributeDefinitionAction extends AttributeAction
{
    public function execute(User $actor, Company $company, AttributeDefinition $definition): AttributeDefinition
    {
        $company = $this->authorize($actor, $company);
        $this->assertDefinitionTenant($company, $definition);

        return DB::transaction(function () use ($actor, $company, $definition): AttributeDefinition {
            $company = $this->authorize($actor, $company);
            $definition = AttributeDefinition::query()->forCompany($company)->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();

            if ($definition->status === AttributeDefinitionStatus::Archived) {
                return $definition;
            }

            $definition->forceFill(['status' => AttributeDefinitionStatus::Archived, 'updated_by' => $actor->getKey()])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeArchived, $actor, $definition, [
                'attribute_uuid' => $definition->uuid,
                'code' => $definition->code,
                'data_type' => $definition->type->value,
                'scope' => $definition->scope->value,
            ]);

            return $definition->refresh();
        });
    }
}
