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
        public ?string $requirementKey = null,
        public ?string $profile = null,
        public ?int $profileVersion = null,
        public ?string $requirementLevel = null,
        public ?string $source = null,
        public ?string $sourceReference = null,
        public ?string $appliesBecause = null,
        public string $applicability = 'applicable',
        public ?string $applicabilityReason = null,
    ) {}

    public function withRegulatoryMetadata(
        ?string $requirementKey,
        ?string $profile,
        ?int $profileVersion,
        ?string $requirementLevel,
        ?string $source,
        ?string $sourceReference,
        ?string $appliesBecause,
        string $applicability,
        ?string $applicabilityReason,
    ): self {
        return new self(
            code: $this->code,
            group: $this->group,
            severity: $this->severity,
            status: $this->status,
            titleKey: $this->titleKey,
            messageKey: $this->messageKey,
            section: $this->section,
            field: $this->field,
            navigationTarget: $this->navigationTarget,
            safeContext: $this->safeContext,
            requirementKey: $requirementKey,
            profile: $profile,
            profileVersion: $profileVersion,
            requirementLevel: $requirementLevel,
            source: $source,
            sourceReference: $sourceReference,
            appliesBecause: $appliesBecause,
            applicability: $applicability,
            applicabilityReason: $applicabilityReason,
        );
    }
}
