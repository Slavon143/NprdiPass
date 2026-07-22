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
        public int $ruleSetVersion,
        public string $scoreAlgorithm,
        public int $scoreAlgorithmVersion,
        public string $ruleSetFingerprint,
        public PassportReadinessStatus $status,
        public int $score,
        public ReadinessScoreBreakdown $scoreBreakdown,
        public ReadinessSummary $counts,
        public array $rules,
        public CarbonImmutable $evaluatedAt,
    ) {}
}
