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

class PassportOptionalSectionsNone implements PassportReadinessRule
{
    public function code(): string
    {
        return 'passport.optional_sections.none';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Passport;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $enabledSections = $context->normalizedPayload['enabled_sections'] ?? [];

        $optionalSections = array_map(
            fn (DppSectionKey $key) => $key->value,
            array_values(array_filter(
                DppSectionKey::cases(),
                fn (DppSectionKey $key) => $key->isOptional(),
            )),
        );

        $enabledOptionals = array_intersect($enabledSections, $optionalSections);
        $passed = count($enabledOptionals) > 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.optional_sections.none.title',
            messageKey: $passed ? 'readiness.passport.optional_sections.none.passed' : 'readiness.passport.optional_sections.none.failed',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: null,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Passport',
            ),
            safeContext: [
                'enabled_optional_sections' => array_values($enabledOptionals),
            ],
        );
    }
}
