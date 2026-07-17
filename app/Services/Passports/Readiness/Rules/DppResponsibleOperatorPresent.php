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

class DppResponsibleOperatorPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.responsible_operator.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Manufacturer;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $operatorName = $context->normalizedPayload['data']['manufacturer_and_operator']['responsible_operator_display_name']
            ?? null;

        $passed = ! empty($operatorName);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.responsible_operator.present.title',
            messageKey: $passed ? 'readiness.dpp.responsible_operator.present.passed' : 'readiness.dpp.responsible_operator.present.failed',
            section: DppSectionKey::ManufacturerAndOperator,
            field: 'responsible_operator_display_name',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::ManufacturerAndOperator->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Manufacturer',
            ),
            safeContext: [
                'operator_name_exists' => ! empty($operatorName),
            ],
        );
    }
}
