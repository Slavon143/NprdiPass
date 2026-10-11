<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\DppRequirementLevel;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Models\Catalog\AttributeDefinition;

class CatalogProductAttributesPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'catalog.product.attributes.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Catalog;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $definitionIds = $context->productAttributeDefinitionIds !== null ? collect($context->productAttributeDefinitionIds) : AttributeDefinition::query()->forCompany($context->company)
            ->where('status', AttributeDefinitionStatus::Active->value)
            ->whereIn('scope', [AttributeScope::Product->value, AttributeScope::Both->value])
            ->pluck('id');
        $explicitlyRequired = array_any($context->effectiveRequirements, fn ($requirement): bool => ($requirement->rule_code ?? $requirement->key) === $this->code()
            && $requirement->level === DppRequirementLevel::Required);
        $hasDefinitions = $definitionIds->isNotEmpty();
        $hasAttributes = $context->product !== null && ($context->product->relationLoaded('attributeValues')
            ? $context->product->attributeValues->contains(fn ($value): bool => (int) $value->company_id === (int) $context->company->id
                && (int) $value->product_id === (int) $context->product->id
                && $definitionIds->contains($value->attribute_definition_id))
            : $context->product->attributeValues()->where('company_id', $context->company->id)
                ->whereIn('attribute_definition_id', $definitionIds)->exists());
        $notApplicable = ! $hasDefinitions && ! $explicitlyRequired;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $notApplicable ? ReadinessRuleStatus::NotApplicable : ($hasAttributes ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed),
            titleKey: 'readiness.catalog.product.attributes.present.title',
            messageKey: 'readiness.catalog.product.attributes.present.'.($notApplicable ? 'not_applicable' : ($hasAttributes ? 'passed' : ($hasDefinitions ? 'failed' : 'setup_required'))),
            navigationTarget: $hasAttributes || $notApplicable ? null : new ReadinessNavigationTarget(
                type: 'catalog_product',
                section: null,
                routeName: $hasDefinitions ? 'catalog.products.attributes.edit' : 'catalog.attributes.index',
                routeParameters: $hasDefinitions ? ['product' => $context->product->uuid ?? ''] : [],
                label: $hasDefinitions ? 'Product attributes' : 'Manage attributes',
            ),
            safeContext: [
                'has_attributes' => $hasAttributes,
                'not_applicable_reason' => $notApplicable ? 'no_active_product_attribute_definitions' : null,
            ],
        );
    }
}
