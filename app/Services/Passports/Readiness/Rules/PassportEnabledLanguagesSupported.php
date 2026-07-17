<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Services\Passports\Localization\PassportLocaleRegistry;

class PassportEnabledLanguagesSupported
{
    public function __construct(
        private readonly PassportLocaleRegistry $localeRegistry,
    ) {}

    /**
     * @return ReadinessRuleResult[]
     */
    public function evaluate(ReadinessEvaluationContext $context): array
    {
        $passport = $context->passport;

        if ($passport === null) {
            return [];
        }

        $enabledLanguages = $passport->enabled_languages ?? [];
        $results = [];

        foreach ($enabledLanguages as $lang) {
            $passed = $this->localeRegistry->supports($lang);

            if (! $passed) {
                $results[] = new ReadinessRuleResult(
                    code: 'passport.languages.enabled_unsupported',
                    group: ReadinessRuleGroup::Passport,
                    severity: ReadinessSeverity::Blocker,
                    status: ReadinessRuleStatus::Failed,
                    titleKey: 'readiness.passport.languages.enabled_unsupported.title',
                    messageKey: 'readiness.passport.languages.enabled_unsupported.failed',
                    safeContext: [
                        'language' => $lang,
                    ],
                );
            }
        }

        return $results;
    }
}
