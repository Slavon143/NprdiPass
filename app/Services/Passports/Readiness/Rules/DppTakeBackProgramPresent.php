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
        $recyclingData = $context->sectionData(DppSectionKey::RecyclingAndDisposal->value);

        $takeBackProgram = $recyclingData['take_back_program'] ?? null;
        $takeBackAvailable = $recyclingData['take_back_program_available'] ?? null;

        $passed = $takeBackAvailable === false || ! empty($takeBackProgram);
        $messageKey = match (true) {
            $passed => 'readiness.dpp.take_back_program.present.passed',
            $takeBackAvailable === true => 'readiness.dpp.take_back_program.present.details_missing',
            default => 'readiness.dpp.take_back_program.present.declaration_missing',
        };

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.take_back_program.present.title',
            messageKey: $messageKey,
            section: DppSectionKey::RecyclingAndDisposal,
            field: $takeBackAvailable === true ? 'take_back_program' : 'take_back_program_available',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::RecyclingAndDisposal->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Recycling',
            ),
            safeContext: [
                'take_back_program_exists' => ! empty($takeBackProgram),
                'take_back_program_available' => $takeBackAvailable,
            ],
        );
    }
}
