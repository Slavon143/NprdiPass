<?php

namespace App\Actions\Catalog\Attributes;

use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreAttributeOptionAction extends AttributeAction
{
    public function execute(User $actor, Company $company, AttributeDefinition $definition, AttributeOption $option): AttributeOption
    {
        $company = $this->authorize($actor, $company);
        $this->assertDefinitionTenant($company, $definition);
        $this->assertOptionOwner($company, $definition, $option);

        return DB::transaction(function () use ($actor, $company, $definition, $option): AttributeOption {
            $company = $this->authorize($actor, $company);
            $definition = AttributeDefinition::query()->forCompany($company)->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();

            if ($definition->status !== AttributeDefinitionStatus::Active) {
                throw AttributeOperationException::invalid('option', 'Restore the attribute before restoring its option.');
            }

            $option = AttributeOption::query()->forCompany($company)->where('attribute_definition_id', $definition->getKey())->whereKey($option->getKey())->lockForUpdate()->firstOrFail();

            if ($option->status === AttributeOptionStatus::Active) {
                return $option;
            }

            $option->forceFill(['status' => AttributeOptionStatus::Active])->save();
            $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeOptionRestored, $actor, $definition, [
                'attribute_uuid' => $definition->uuid,
                'option_id' => $option->getKey(),
                'option_code' => $option->code,
            ]);

            return $option->refresh()->load('definition');
        });
    }
}
