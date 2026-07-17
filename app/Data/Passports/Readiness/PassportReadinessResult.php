<?php

namespace App\Data\Passports\Readiness;

use App\Enums\Passports\Readiness\PassportReadinessStatus;
use Carbon\CarbonImmutable;

readonly class PassportReadinessResult
{
    /**
     * @param  ReadinessRuleResult[]  $rules
     */
    public function __construct(
        public string $profile,
        public int $profileVersion,
        public int $schemaVersion,
        public string $passportUuid,
        public ?string $draftVersionUuid,
        public int $passportRevision,
        public PassportReadinessStatus $status,
        public int $score,
        public ReadinessSummary $counts,
        public array $rules,
        public CarbonImmutable $evaluatedAt,
    ) {}
}
