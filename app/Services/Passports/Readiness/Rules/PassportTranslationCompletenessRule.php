<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Services\Passports\Localization\PassportTranslationCompletenessEvaluator;

class PassportTranslationCompletenessRule implements PassportReadinessRule
{
    public function __construct(
        private readonly PassportTranslationCompletenessEvaluator $completenessEvaluator,
    ) {}

    public function code(): string
    {
        return 'passport.languages.translation_completeness';
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

        if ($passport === null || $context->currentDraft === null) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.passport.languages.translation_completeness.title',
                messageKey: 'readiness.passport.languages.translation_completeness.skipped',
                safeContext: [],
            );
        }

        $payload = $context->normalizedPayload;
        $enabledLocales = $passport->enabled_languages ?? [];
        $defaultLocale = $passport->default_language;

        $results = $this->completenessEvaluator->evaluate($payload, $enabledLocales, $defaultLocale);
        $defaultResult = $results[$defaultLocale] ?? null;

        $passed = $defaultResult !== null && ! $defaultResult->hasRequiredMissing();

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $passed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.passport.languages.translation_completeness.title',
            messageKey: $passed
                ? 'readiness.passport.languages.translation_completeness.passed'
                : 'readiness.passport.languages.translation_completeness.failed',
            safeContext: [
                'default_locale' => $defaultLocale,
                'completion' => $defaultResult?->completion,
            ],
        );
    }
}
