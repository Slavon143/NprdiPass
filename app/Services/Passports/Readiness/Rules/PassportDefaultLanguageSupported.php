<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Services\Passports\Localization\PassportLocaleRegistry;

class PassportDefaultLanguageSupported implements PassportReadinessRule
{
    public function __construct(
        private readonly PassportLocaleRegistry $localeRegistry,
    ) {}

    public function code(): string
    {
        return 'passport.languages.default_supported';
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

        $passed = $passport !== null && $this->localeRegistry->supports($passport->default_language);

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.languages.default_supported.title',
            messageKey: $passed ? 'readiness.passport.languages.default_supported.passed' : 'readiness.passport.languages.default_supported.failed',
            safeContext: [
                'default_language' => $passport?->default_language,
                'supported' => $passed,
            ],
        );
    }
}
