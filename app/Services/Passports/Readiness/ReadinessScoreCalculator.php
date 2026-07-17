<?php

namespace App\Services\Passports\Readiness;

use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;

class ReadinessScoreCalculator
{
    /**
     * @param  ReadinessRuleResult[]  $ruleResults
     */
    public function calculate(array $ruleResults): int
    {
        $totalWeight = 0;
        $passedWeight = 0;

        foreach ($ruleResults as $result) {
            if ($result->status === ReadinessRuleStatus::NotApplicable) {
                continue;
            }

            $weight = config("passport_readiness.score_weights.{$result->severity->value}") ?? 0;
            $totalWeight += $weight;

            if ($result->status === ReadinessRuleStatus::Passed) {
                $passedWeight += $weight;
            }
        }

        if ($totalWeight === 0) {
            return 100;
        }

        $score = (int) round($passedWeight / $totalWeight * 100);

        return max(0, min(100, $score));
    }
}
