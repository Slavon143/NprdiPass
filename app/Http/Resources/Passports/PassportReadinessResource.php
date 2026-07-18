<?php

namespace App\Http\Resources\Passports;

use App\Data\Passports\Readiness\PassportReadinessResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PassportReadinessResult */
class PassportReadinessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'profile' => $this->profile,
            'profile_version' => $this->profileVersion,
            'schema_version' => $this->schemaVersion,
            'passport_uuid' => $this->passportUuid,
            'draft_version_uuid' => $this->draftVersionUuid,
            'passport_revision' => $this->passportRevision,
            'status' => $this->status->value,
            'score' => $this->score,
            'score_breakdown' => $this->scoreBreakdown(),
            'counts' => [
                'passed' => $this->counts->passed,
                'blockers' => $this->counts->blockers,
                'warnings' => $this->counts->warnings,
                'recommendations' => $this->counts->recommendations,
                'not_applicable' => $this->counts->notApplicable,
            ],
            'rules' => ReadinessRuleResource::collection($this->rules),
            'evaluated_at' => $this->evaluatedAt->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function scoreBreakdown(): array
    {
        $weights = config('passport_readiness.score_weights', []);
        $passedPoints = 0;
        $failedPointsBySeverity = [
            'blocker' => 0,
            'warning' => 0,
            'recommendation' => 0,
        ];

        foreach ($this->rules as $rule) {
            if ($rule->status->value === 'not_applicable') {
                continue;
            }

            $weight = (int) ($weights[$rule->severity->value] ?? 0);

            if ($rule->status->value === 'passed') {
                $passedPoints += $weight;

                continue;
            }

            $failedPointsBySeverity[$rule->severity->value] = ($failedPointsBySeverity[$rule->severity->value] ?? 0) + $weight;
        }

        $failedPoints = array_sum($failedPointsBySeverity);

        return [
            'weights' => $weights,
            'passed_points' => $passedPoints,
            'failed_points' => $failedPoints,
            'failed_points_by_severity' => $failedPointsBySeverity,
            'applicable_points' => $passedPoints + $failedPoints,
            'not_applicable_rules_excluded' => $this->counts->notApplicable,
        ];
    }
}
