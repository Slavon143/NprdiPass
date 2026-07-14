<?php

namespace App\Actions\Catalog\Attributes;

use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ArchiveAttributeOptionAction extends AttributeAction
{
    public function execute(User $actor, Company $company, AttributeDefinition $definition, AttributeOption $option): AttributeOption
    {
        $company = $this->authorize($actor, $company);
        $this->assertDefinitionTenant($company, $definition);
        $this->assertOptionOwner($company, $definition, $option);

        return DB::transaction(function () use ($actor, $company, $definition, $option): AttributeOption {
            $company = $this->authorize($actor, $company);
            $definition = AttributeDefinition::query()->forCompany($company)->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();
            $option = AttributeOption::query()->forCompany($company)->where('attribute_definition_id', $definition->getKey())->whereKey($option->getKey())->lockForUpdate()->firstOrFail();

            if ($option->status === AttributeOptionStatus::Archived) {
                return $option;
            }

            $option->forceFill(['status' => AttributeOptionStatus::Archived])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeOptionArchived, $actor, $definition, [
                'attribute_uuid' => $definition->uuid,
                'option_id' => $option->getKey(),
                'option_code' => $option->code,
            ]);

            return $option->refresh()->load('definition');
        });
    }
}
