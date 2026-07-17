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

class DppTakeBackProgramPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.take_back_program.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Recycling;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $takeBackProgram = $context->normalizedPayload['data']['recycling_and_disposal']['take_back_program']
            ?? null;

        $passed = ! empty($takeBackProgram);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.take_back_program.present.title',
            messageKey: $passed ? 'readiness.dpp.take_back_program.present.passed' : 'readiness.dpp.take_back_program.present.failed',
            section: DppSectionKey::RecyclingAndDisposal,
            field: 'take_back_program',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::RecyclingAndDisposal->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Recycling',
            ),
            safeContext: [
                'take_back_program_exists' => ! empty($takeBackProgram),
            ],
        );
    }
}
