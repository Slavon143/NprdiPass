<?php

namespace App\Services\Passports\Readiness;

use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Data\Passports\Readiness\ReadinessScoreBreakdown;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use InvalidArgumentException;

class ReadinessScoreCalculator
{
    /**
     * @param  ReadinessRuleResult[]  $ruleResults
     */
    public function calculate(array $ruleResults, ?array $weights = null): int
    {
        return $this->breakdown($ruleResults, $weights)->score;
    }

    /**
     * @param  ReadinessRuleResult[]  $ruleResults
     */
    public function breakdown(array $ruleResults, ?array $weights = null): ReadinessScoreBreakdown
    {
        $weights = $this->weights($weights);
        $applicablePoints = 0;
        $earnedPoints = 0;
        $notApplicableRules = 0;
        $seenCodes = [];
        $failedPointsBySeverity = [
            'blocker' => 0,
            'warning' => 0,
            'recommendation' => 0,
        ];

        foreach ($ruleResults as $result) {
            if (isset($seenCodes[$result->code])) {
                throw new InvalidArgumentException("Duplicate readiness rule code: {$result->code}.");
            }

            $seenCodes[$result->code] = true;

            if ($result->status === ReadinessRuleStatus::NotApplicable) {
                $notApplicableRules++;

                continue;
            }

            $weight = $weights[$result->severity->value];
            $applicablePoints += $weight;

            if ($result->status === ReadinessRuleStatus::Passed) {
                $earnedPoints += $weight;
            } else {
                $failedPointsBySeverity[$result->severity->value] += $weight;
            }
        }

        $score = $applicablePoints === 0
            ? 100
            : max(0, min(100, (int) round($earnedPoints / $applicablePoints * 100)));

        return new ReadinessScoreBreakdown(
            score: $score,
            earnedPoints: $earnedPoints,
            applicablePoints: $applicablePoints,
            notApplicableRules: $notApplicableRules,
            weights: $weights,
            failedPointsBySeverity: $failedPointsBySeverity,
        );
    }

    /** @return array{blocker: int, warning: int, recommendation: int} */
    private function weights(?array $configured = null): array
    {
        $configured ??= app(ReadinessProfileRepository::class)->active()->weights;
        $weights = [];

        foreach (ReadinessSeverity::cases() as $severity) {
            $weight = $configured[$severity->value] ?? null;

            if (! is_int($weight) || $weight <= 0) {
                throw new InvalidArgumentException(
                    "Readiness score weight for {$severity->value} must be a positive integer.",
                );
            }

            $weights[$severity->value] = $weight;
        }

        return $weights;
    }
}
