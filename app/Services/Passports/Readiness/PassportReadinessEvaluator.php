<?php

namespace App\Services\Passports\Readiness;

use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Data\Passports\Readiness\ReadinessSummary;
use App\Enums\Passports\Readiness\PassportReadinessStatus;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use Carbon\CarbonImmutable;

class PassportReadinessEvaluator
{
    private PassportReadinessRuleRegistry $registry;

    private ReadinessScoreCalculator $scoreCalculator;

    public function __construct(
        PassportReadinessRuleRegistry $registry,
        ReadinessScoreCalculator $scoreCalculator,
        private readonly ReadinessProfileRepository $profileRepository,
    ) {
        $this->registry = $registry;
        $this->scoreCalculator = $scoreCalculator;
    }

    public function evaluate(ReadinessEvaluationContext $context): PassportReadinessResult
    {
        $profile = $context->readinessProfile ?? $this->profileRepository->active();
        $rules = $this->registry->all($profile);
        $ruleResults = [];

        foreach ($rules as $rule) {
            $ruleResults[] = $rule->evaluate($context);
        }

        $counts = $this->buildSummary($ruleResults);
        $breakdown = $this->scoreCalculator->breakdown($ruleResults, $profile->weights);
        $status = self::determineStatus($ruleResults);

        return new PassportReadinessResult(
            profile: $profile->code,
            profileVersion: $profile->version,
            schemaVersion: $context->currentDraft !== null ? (int) $context->currentDraft->schema_version : 1,
            passportUuid: $context->passport !== null ? $context->passport->uuid : '',
            draftVersionUuid: $context->currentDraft !== null ? $context->currentDraft->uuid : null,
            passportRevision: $context->currentDraft !== null ? $context->currentDraft->draft_revision : 0,
            ruleSetVersion: $profile->ruleSetVersion,
            scoreAlgorithm: $profile->scoreAlgorithm,
            scoreAlgorithmVersion: $profile->scoreAlgorithmVersion,
            ruleSetFingerprint: $profile->fingerprint,
            status: $status,
            score: $breakdown->score,
            scoreBreakdown: $breakdown,
            counts: $counts,
            rules: $ruleResults,
            evaluatedAt: new CarbonImmutable,
        );
    }

    /**
     * @param  ReadinessRuleResult[]  $ruleResults
     */
    public static function determineStatus(array $ruleResults): PassportReadinessStatus
    {
        foreach ($ruleResults as $result) {
            if ($result->status !== ReadinessRuleStatus::Failed) {
                continue;
            }

            if ($result->severity === ReadinessSeverity::Blocker) {
                return PassportReadinessStatus::NotReady;
            }
        }

        foreach ($ruleResults as $result) {
            if ($result->status === ReadinessRuleStatus::Failed
                && $result->severity === ReadinessSeverity::Warning) {
                return PassportReadinessStatus::ReadyWithWarnings;
            }
        }

        return PassportReadinessStatus::Ready;
    }

    /**
     * @param  ReadinessRuleResult[]  $ruleResults
     */
    private function buildSummary(array $ruleResults): ReadinessSummary
    {
        $passed = 0;
        $blockers = 0;
        $warnings = 0;
        $recommendations = 0;
        $notApplicable = 0;

        foreach ($ruleResults as $result) {
            if ($result->status === ReadinessRuleStatus::NotApplicable) {
                $notApplicable++;

                continue;
            }

            if ($result->status === ReadinessRuleStatus::Passed) {
                $passed++;
            } else {
                match ($result->severity) {
                    ReadinessSeverity::Blocker => $blockers++,
                    ReadinessSeverity::Warning => $warnings++,
                    ReadinessSeverity::Recommendation => $recommendations++,
                };
            }
        }

        return new ReadinessSummary(
            passed: $passed,
            blockers: $blockers,
            warnings: $warnings,
            recommendations: $recommendations,
            notApplicable: $notApplicable,
        );
    }
}
