<?php

namespace App\Data\Passports\Readiness;

use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

readonly class ReadinessRuleResult
{
    /**
     * @param  array<string, mixed>  $safeContext
     */
    public function __construct(
        public string $code,
        public ReadinessRuleGroup $group,
        public ReadinessSeverity $severity,
        public ReadinessRuleStatus $status,
        public string $titleKey,
        public string $messageKey,
        public ?DppSectionKey $section = null,
        public ?string $field = null,
        public ?ReadinessNavigationTarget $navigationTarget = null,
        public array $safeContext = [],
    ) {}
}
