<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Services\Passports\Localization\PassportLocaleRegistry;

class PassportEnabledLanguagesSupported implements PassportReadinessRule
{
    public function __construct(
        private readonly PassportLocaleRegistry $localeRegistry,
    ) {}

    public function code(): string
    {
        return 'passport.languages.enabled_unsupported';
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
        $passport = $context->passport;

        if ($passport === null) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.passport.languages.enabled_unsupported.title',
                messageKey: 'readiness.passport.languages.enabled_unsupported.skipped',
            );
        }

        $enabledLanguages = $passport->enabled_languages ?? [];
        $unsupported = array_values(array_filter(
            $enabledLanguages,
            fn (string $language): bool => ! $this->localeRegistry->supports($language),
        ));
        $passed = $unsupported === [];

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.languages.enabled_unsupported.title',
            messageKey: $passed
                ? 'readiness.passport.languages.enabled_unsupported.passed'
                : 'readiness.passport.languages.enabled_unsupported.failed',
            safeContext: ['unsupported_languages' => $unsupported],
        );
    }
}
