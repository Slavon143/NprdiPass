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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreateAttributeOptionAction extends AttributeAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, Company $company, AttributeDefinition $definition, array $data): AttributeOption
    {
        $company = $this->authorize($actor, $company);
        $this->assertDefinitionTenant($company, $definition);

        try {
            return DB::transaction(function () use ($actor, $company, $definition, $data): AttributeOption {
                $company = $this->authorize($actor, $company);
                $definition = AttributeDefinition::query()->forCompany($company)->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();

                if ($definition->status !== AttributeDefinitionStatus::Active || ! $definition->usesOptions()) {
                    throw AttributeOperationException::invalid('option', 'Options are available only for active select and multiselect attributes.');
                }

                if ($definition->options()->lockForUpdate()->count() >= self::MAX_OPTIONS) {
                    throw AttributeOperationException::invalid('option', 'The maximum number of options has been reached.');
                }

                $option = new AttributeOption;
                $option->forceFill([
                    'company_id' => $company->getKey(),
                    'attribute_definition_id' => $definition->getKey(),
                    ...$this->optionData($data),
                    'status' => AttributeOptionStatus::Active,
                ])->save();

                $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeOptionCreated, $actor, $definition, [
                    'attribute_uuid' => $definition->uuid,
                    'option_id' => $option->getKey(),
                    'option_code' => $option->code,
                ]);

                return $option->refresh()->load('definition');
            });
        } catch (QueryException $exception) {
            throw $this->mapDuplicate($exception) ?? $exception;
        }
    }
}
