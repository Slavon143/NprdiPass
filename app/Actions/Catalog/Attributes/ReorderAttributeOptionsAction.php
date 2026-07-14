<?php

namespace App\Actions\Catalog\Attributes;

use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Exceptions\Catalog\AttributeOperationException;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderAttributeOptionsAction extends AttributeAction
{
    /** @param list<int> $orderedOptionIds */
    public function execute(User $actor, Company $company, AttributeDefinition $definition, array $orderedOptionIds): AttributeDefinition
    {
        $company = $this->authorize($actor, $company);
        $this->assertDefinitionTenant($company, $definition);

        return DB::transaction(function () use ($actor, $company, $definition, $orderedOptionIds): AttributeDefinition {
            $company = $this->authorize($actor, $company);
            $definition = AttributeDefinition::query()->forCompany($company)->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();
            $options = AttributeOption::query()->forCompany($company)
                ->where('attribute_definition_id', $definition->getKey())
                ->where('status', AttributeOptionStatus::Active->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $expected = $options->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $received = array_map('intval', $orderedOptionIds);
            $sortedReceived = $received;
            sort($sortedReceived);

            if (count($received) !== count(array_unique($received)) || $sortedReceived !== $expected) {
                throw AttributeOperationException::invalid('option_ids', 'Provide every active option exactly once.');
            }

            $changed = false;

            foreach ($received as $index => $id) {
                $option = $options->firstWhere('id', $id);
                $sortOrder = ($index + 1) * 10;

                if (! $option instanceof AttributeOption) {
                    throw AttributeOperationException::optionMismatch();
                }

                if ($option->sort_order !== $sortOrder) {
                    $option->forceFill(['sort_order' => $sortOrder])->save();
                    $changed = true;
                }
            }

            if ($changed) {
                $this->auditLogger->logTenant($company, AuditEvent::CatalogAttributeOptionsReordered, $actor, $definition, [
                    'attribute_uuid' => $definition->uuid,
                    'ordered_option_ids' => $received,
                    'option_count' => count($received),
                ]);
            }

            return $definition->refresh()->load(['options' => fn ($query) => $query->ordered()]);
        });
    }
}
