<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Services\Passports\DppPayloadValidator;
use Illuminate\Validation\ValidationException;

class DppMaterialsValid implements PassportReadinessRule
{
    private DppPayloadValidator $validator;

    public function __construct(DppPayloadValidator $validator)
    {
        $this->validator = $validator;
    }

    public function code(): string
    {
        return 'dpp.materials.valid';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Technical;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $enabledSections = $context->normalizedPayload['enabled_sections'] ?? [];

        if (! in_array(DppSectionKey::MaterialsAndComposition->value, $enabledSections, true)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.materials.valid.title',
                messageKey: 'readiness.dpp.materials.valid.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $materialsData = $context->normalizedPayload['data']['materials_and_composition'] ?? [];

        if (empty($materialsData)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Passed,
                titleKey: 'readiness.dpp.materials.valid.title',
                messageKey: 'readiness.dpp.materials.valid.passed',
                safeContext: ['materials_empty' => true],
            );
        }

        try {
            $this->validator->validateSectionPayload(
                DppSectionKey::MaterialsAndComposition->value,
                $materialsData,
                false,
                $context->passport->default_language ?? 'sv',
            );

            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Passed,
                titleKey: 'readiness.dpp.materials.valid.title',
                messageKey: 'readiness.dpp.materials.valid.passed',
                safeContext: [],
            );
        } catch (ValidationException $e) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Failed,
                titleKey: 'readiness.dpp.materials.valid.title',
                messageKey: 'readiness.dpp.materials.valid.failed',
                section: DppSectionKey::MaterialsAndComposition,
                navigationTarget: new ReadinessNavigationTarget(
                    type: 'passport_section',
                    section: DppSectionKey::MaterialsAndComposition->value,
                    routeName: 'catalog.products.passport.edit',
                    routeParameters: ['product' => $context->product->uuid ?? ''],
                    label: 'Edit Materials',
                ),
                safeContext: [
                    'validation_errors' => $e->errors(),
                ],
            );
        }
    }
}
