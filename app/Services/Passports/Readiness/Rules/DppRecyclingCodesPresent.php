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

class DppRecyclingCodesPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.recycling.codes.present';
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
        $recyclingCodes = $context->normalizedPayload['data']['recycling_and_disposal']['recycling_codes']
            ?? null;

        $passed = is_array($recyclingCodes) && count($recyclingCodes) > 0;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.recycling.codes.present.title',
            messageKey: $passed ? 'readiness.dpp.recycling.codes.present.passed' : 'readiness.dpp.recycling.codes.present.failed',
            section: DppSectionKey::RecyclingAndDisposal,
            field: 'recycling_codes',
            navigationTarget: new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::RecyclingAndDisposal->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Recycling',
            ),
            safeContext: [
                'recycling_codes_count' => is_array($recyclingCodes) ? count($recyclingCodes) : 0,
            ],
        );
    }
}
