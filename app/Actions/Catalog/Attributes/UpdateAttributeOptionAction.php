<?php

namespace App\Actions\Catalog\Attributes;

use App\Enums\AuditEvent;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UpdateAttributeOptionAction extends AttributeAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, Company $company, AttributeDefinition $definition, AttributeOption $option, array $data): AttributeOption
    {
        $company = $this->authorize($actor, $company);
        $this->assertDefinitionTenant($company, $definition);
        $this->assertOptionOwner($company, $definition, $option);

        try {
            return DB::transaction(function () use ($actor, $company, $definition, $option, $data): AttributeOption {
                $company = $this->authorize($actor, $company);
                $definition = AttributeDefinition::query()->forCompany($company)->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();
                $option = AttributeOption::query()->forCompany($company)
                    ->where('attribute_definition_id', $definition->getKey())
                    ->whereKey($option->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $values = $this->optionData($data, $option);
                $used = $option->productSelectValues()->exists()
                    || $option->variantSelectValues()->exists()
                    || $option->productMultiselectAssignments()->exists()
                    || $option->variantMultiselectAssignments()->exists();

                if ($values['code'] !== $option->code && $used) {
                    throw AttributeOperationException::immutable('code', 'Option code cannot be changed after the option has been assigned.');
                }

                $option->forceFill($values);
                $changedFields = array_values(array_filter(array_keys($values), fn (string $field): bool => $option->isDirty($field)));

                if ($changedFields === []) {
                    return $option->load('definition');
                }

                $option->save();
                $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeOptionUpdated, $actor, $definition, [
                    'attribute_uuid' => $definition->uuid,
                    'option_id' => $option->getKey(),
                    'option_code' => $option->code,
                    'changed_fields' => $changedFields,
                ]);

                return $option->refresh()->load('definition');
            });
        } catch (QueryException $exception) {
            throw $this->mapDuplicate($exception) ?? $exception;
        }
    }
}
