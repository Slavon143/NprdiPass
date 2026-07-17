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

class PassportCoreSectionsEnabled implements PassportReadinessRule
{
    public function code(): string
    {
        return 'passport.core_sections.enabled';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Passport;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $enabledSections = $context->normalizedPayload['enabled_sections'] ?? [];

        $coreSections = array_map(
            fn (DppSectionKey $key) => $key->value,
            array_values(array_filter(
                DppSectionKey::cases(),
                fn (DppSectionKey $key) => $key->isCore(),
            )),
        );

        $missing = array_diff($coreSections, $enabledSections);
        $passed = count($missing) === 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.core_sections.enabled.title',
            messageKey: $passed ? 'readiness.passport.core_sections.enabled.passed' : 'readiness.passport.core_sections.enabled.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: null,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Passport',
            ),
            safeContext: [
                'enabled_sections' => $enabledSections,
                'required_core_sections' => $coreSections,
                'missing_core_sections' => array_values($missing),
            ],
        );
    }
}
