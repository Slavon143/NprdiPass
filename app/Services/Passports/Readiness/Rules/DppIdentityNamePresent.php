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

class DppIdentityNamePresent implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.identity.name.present';
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

        $dppName = $context->normalizedPayload['translations'][$defaultLanguage]['identity']['public_name']
            ?? $context->normalizedPayload['translations']['sv']['identity']['public_name']
            ?? null;

        $catalogName = $context->product->name;
        $passed = ! empty($dppName) || ! empty($catalogName);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.identity.name.present.title',
            messageKey: $passed ? 'readiness.dpp.identity.name.present.passed' : 'readiness.dpp.identity.name.present.failed',
            section: DppSectionKey::Identity,
            field: 'public_name',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::Identity->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Identity',
            ),
            safeContext: [
                'dpp_name_exists' => ! empty($dppName),
                'catalog_name_exists' => ! empty($catalogName),
            ],
        );
    }
}
