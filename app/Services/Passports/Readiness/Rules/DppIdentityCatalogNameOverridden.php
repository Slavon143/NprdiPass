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

class DppIdentityCatalogNameOverridden implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.identity.catalog_name_overridden';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Identity;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Warning;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $defaultLanguage = $context->passport->default_language ?? 'sv';

        $dppName = $context->normalizedPayload['translations'][$defaultLanguage]['identity']['public_name']
            ?? $context->normalizedPayload['translations']['sv']['identity']['public_name']
            ?? null;

        $catalogName = $context->product->name;

        if (empty($dppName) || empty($catalogName)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::Passed,
                titleKey: 'readiness.dpp.identity.catalog_name_overridden.title',
                messageKey: 'readiness.dpp.identity.catalog_name_overridden.passed',
                section: DppSectionKey::Identity,
                field: 'public_name',
                safeContext: [
                    'dpp_name_exists' => ! empty($dppName),
                    'catalog_name_exists' => ! empty($catalogName),
                ],
            );
        }

        $differs = $dppName !== $catalogName;
        $passed = $differs;

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $differs ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.identity.catalog_name_overridden.title',
            messageKey: $differs ? 'readiness.dpp.identity.catalog_name_overridden.passed' : 'readiness.dpp.identity.catalog_name_overridden.failed',
            section: DppSectionKey::Identity,
            field: 'public_name',
            navigationTarget: $differs ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::Identity->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Identity',
            ),
            safeContext: [
                'dpp_name' => $dppName ?? '',
                'catalog_name' => $catalogName,
                'names_differ' => $differs,
            ],
        );
    }
}
