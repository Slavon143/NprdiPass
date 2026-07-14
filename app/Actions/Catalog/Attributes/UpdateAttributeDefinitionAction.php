<?php

namespace App\Actions\Catalog\Attributes;

use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeScope;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UpdateAttributeDefinitionAction extends AttributeAction
{
    /** @param array<string, mixed> $data */
    public function execute(User $actor, Company $company, AttributeDefinition $definition, array $data): AttributeDefinition
    {
        $company = $this->authorize($actor, $company);
        $this->assertDefinitionTenant($company, $definition);

        try {
            return DB::transaction(function () use ($actor, $company, $definition, $data): AttributeDefinition {
                $company = $this->authorize($actor, $company);
                $definition = AttributeDefinition::query()->forCompany($company)->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();
                $hasProductValues = $definition->productValues()->lockForUpdate()->exists();
                $hasVariantValues = $definition->variantValues()->lockForUpdate()->exists();
                $hasOptions = $definition->options()->lockForUpdate()->exists();
                $values = $this->definitionData($data, $definition);

                if ($values['code'] !== $definition->code && ($hasProductValues || $hasVariantValues)) {
                    throw AttributeOperationException::immutable('code', 'Code cannot be changed after values have been assigned.');
                }

                if ($values['type'] !== $definition->type && ($hasOptions || $hasProductValues || $hasVariantValues)) {
                    throw AttributeOperationException::immutable('type', 'Data type cannot be changed after options or values exist.');
                }

                if ($values['scope'] !== $definition->scope) {
                    $blocksProduct = $hasProductValues && $values['scope'] === AttributeScope::Variant;
                    $blocksVariant = $hasVariantValues && $values['scope'] === AttributeScope::Product;

                    if ($blocksProduct || $blocksVariant) {
                        throw AttributeOperationException::immutable('scope', 'Scope cannot exclude existing Product or Variant values.');
                    }
                }

                $definition->forceFill($values);
                $changedFields = array_values(array_filter(array_keys($values), fn (string $field): bool => $definition->isDirty($field)));

                if ($changedFields === []) {
                    return $definition;
                }

                $definition->forceFill(['updated_by' => $actor->getKey()])->save();
                $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeUpdated, $actor, $definition, [
                    'attribute_uuid' => $definition->uuid,
                    'code' => $definition->code,
                    'data_type' => $definition->type->value,
                    'scope' => $definition->scope->value,
                    'changed_fields' => $changedFields,
                ]);

                return $definition->refresh();
            });
        } catch (QueryException $exception) {
            throw $this->mapDuplicate($exception) ?? $exception;
        }
    }
}
