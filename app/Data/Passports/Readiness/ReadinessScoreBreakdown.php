<?php

namespace App\Data\Passports\Readiness;

readonly class ReadinessScoreBreakdown
{
    /**
     * @param  array{blocker: int, warning: int, recommendation: int}  $weights
     * @param  array{blocker: int, warning: int, recommendation: int}  $failedPointsBySeverity
     */
    public function __construct(
        public int $score,
        public int $earnedPoints,
        public int $applicablePoints,
        public int $notApplicableRules,
        public array $weights,
        public array $failedPointsBySeverity,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'weights' => $this->weights,
            'earned_points' => $this->earnedPoints,
            'passed_points' => $this->earnedPoints,
            'failed_points' => $this->applicablePoints - $this->earnedPoints,
            'failed_points_by_severity' => $this->failedPointsBySeverity,
            'applicable_points' => $this->applicablePoints,
            'score' => $this->score,
            'not_applicable_rules_excluded' => $this->notApplicableRules,
        ];
    }
}
