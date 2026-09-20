<?php

namespace App\Services\Catalog\Taxonomy;

use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionApprovalStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TaxonomyLifecycleService
{
    public function deprecateDefinition(AttributeDefinition $definition, AttributeDefinition $replacement): AttributeDefinition
    {
        if ($definition->status !== AttributeDefinitionStatus::Active
            || (int) $definition->company_id !== (int) $replacement->company_id
            || $definition->is($replacement)
            || $definition->type !== $replacement->type
            || $definition->scope !== $replacement->scope
            || $replacement->status !== AttributeDefinitionStatus::Active) {
            throw ValidationException::withMessages([
                'replacement' => 'The replacement must be a different active attribute of the same company, type, and scope.',
            ]);
        }

        $definition->forceFill([
            'status' => AttributeDefinitionStatus::Deprecated,
            'replaced_by_attribute_definition_id' => $replacement->getKey(),
        ])->save();

        return $definition->refresh()->load('replacement');
    }

    public function deprecateOption(AttributeOption $option, AttributeOption $replacement): AttributeOption
    {
        if ($option->status !== AttributeOptionStatus::Active
            || (int) $option->company_id !== (int) $replacement->company_id
            || (int) $option->attribute_definition_id !== (int) $replacement->attribute_definition_id
            || $option->is($replacement)
            || $replacement->status !== AttributeOptionStatus::Active
            || $replacement->approval_status !== AttributeOptionApprovalStatus::Approved) {
            throw ValidationException::withMessages([
                'replacement' => 'The replacement must be a different approved active option from the same attribute.',
            ]);
        }

        $option->forceFill([
            'status' => AttributeOptionStatus::Deprecated,
            'replaced_by_attribute_option_id' => $replacement->getKey(),
        ])->save();

        return $option->refresh()->load('replacement');
    }

    public function approveOption(AttributeOption $option, User $actor): AttributeOption
    {
        $option->loadMissing('definition');

        if ($option->status !== AttributeOptionStatus::Active
            || $option->definition->status !== AttributeDefinitionStatus::Active
            || ! $option->definition->vocabulary_controlled) {
            throw ValidationException::withMessages([
                'option' => 'Only active options of an active controlled vocabulary can be approved.',
            ]);
        }

        $option->forceFill([
            'approval_status' => AttributeOptionApprovalStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $actor->getKey(),
        ])->save();

        return $option->refresh();
    }

    public function deleteOption(AttributeOption $option): void
    {
        $used = $option->productSelectValues()->exists()
            || $option->variantSelectValues()->exists()
            || $option->productMultiselectAssignments()->exists()
            || $option->variantMultiselectAssignments()->exists();

        if ($used) {
            throw ValidationException::withMessages([
                'option' => 'Referenced options cannot be deleted; deprecate or archive the option instead.',
            ]);
        }

        $option->delete();
    }
}
