<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class PassportDefaultLanguageEnabled implements PassportReadinessRule
{
    public function code(): string
    {
        return 'passport.default_language.enabled';
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
        $defaultLanguage = $context->passport->default_language ?? 'sv';
        $translations = $context->normalizedPayload['translations'] ?? [];
        $passed = array_key_exists($defaultLanguage, $translations);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.default_language.enabled.title',
            messageKey: $passed ? 'readiness.passport.default_language.enabled.passed' : 'readiness.passport.default_language.enabled.failed',
            navigationTarget: $passed ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: null,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Edit Passport Settings',
            ),
            safeContext: [
                'default_language' => $defaultLanguage,
                'available_languages' => array_keys($translations),
            ],
        );
    }
}
