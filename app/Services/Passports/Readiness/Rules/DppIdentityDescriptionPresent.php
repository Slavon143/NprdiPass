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

class DppIdentityDescriptionPresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.identity.description.present';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Identity;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Blocker;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $defaultLanguage = $context->passport->default_language ?? 'sv';

        $description = $context->normalizedPayload['translations'][$defaultLanguage]['identity']['public_description']
            ?? $context->normalizedPayload['translations']['sv']['identity']['public_description']
            ?? null;

        $passed = ! empty($description);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.identity.description.present.title',
            messageKey: $passed ? 'readiness.dpp.identity.description.present.passed' : 'readiness.dpp.identity.description.present.failed',
            section: DppSectionKey::Identity,
            field: 'public_description',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::Identity->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Identity',
            ),
            safeContext: [
                'description_exists' => ! empty($description),
            ],
        );
    }
}
