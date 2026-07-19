<?php

namespace App\Actions\Passports;

use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Models\Company;
use App\Models\Passports\PassportValidationResult;
use App\Models\Passports\PassportValidationRun;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Passports\CanonicalJsonEncoder;
use App\Services\Passports\Readiness\ReadinessScoreCalculator;
use Illuminate\Support\Facades\DB;

class RecordPassportValidationRun
{
    public function __construct(
        private readonly ReadinessScoreCalculator $scoreCalculator,
        private readonly CanonicalJsonEncoder $canonicalJsonEncoder,
    ) {}

    public function handle(
        Company $company,
        ProductPassport $passport,
        ProductPassportVersion $draft,
        PassportReadinessResult $result,
        ?User $actor,
    ): PassportValidationRun {
        return DB::transaction(function () use ($company, $passport, $draft, $result, $actor): PassportValidationRun {
            $breakdown = $this->scoreCalculator->breakdown($result->rules);
            $sourceChecksum = $this->canonicalJsonEncoder->hash([
                'passport_uuid' => $passport->uuid,
                'draft_version_uuid' => $draft->uuid,
                'draft_revision' => $draft->draft_revision,
                'schema_version' => $draft->schema_version,
                'payload' => $draft->payload,
                'rules' => array_map(fn ($rule): array => [
                    'code' => $rule->code,
                    'group' => $rule->group->value,
                    'severity' => $rule->severity->value,
                    'status' => $rule->status->value,
                    'safe_context' => $rule->safeContext,
                ], $result->rules),
            ]);

            $run = new PassportValidationRun;
            $run->forceFill([
                'company_id' => $company->getKey(),
                'passport_id' => $passport->getKey(),
                'draft_version_id' => $draft->getKey(),
                'created_by' => $actor?->getKey(),
                'profile' => $result->profile,
                'profile_version' => $result->profileVersion,
                'schema_version' => (string) $result->schemaVersion,
                'rule_set_version' => config('passport_readiness.rule_set_version'),
                'score_algorithm_version' => config('passport_readiness.score_algorithm_version'),
                'weights_snapshot' => $breakdown->weights,
                'earned_points' => $breakdown->earnedPoints,
                'applicable_points' => $breakdown->applicablePoints,
                'score' => $breakdown->score,
                'status' => $result->status->value,
                'draft_revision' => $result->passportRevision,
                'source_checksum' => $sourceChecksum,
                'passed_count' => $result->counts->passed,
                'blocker_count' => $result->counts->blockers,
                'warning_count' => $result->counts->warnings,
                'recommendation_count' => $result->counts->recommendations,
                'not_applicable_count' => $result->counts->notApplicable,
                'validated_at' => $result->evaluatedAt,
            ])->save();

            foreach ($result->rules as $rule) {
                $validationResult = new PassportValidationResult;
                $validationResult->forceFill([
                    'company_id' => $company->getKey(),
                    'validation_run_id' => $run->getKey(),
                    'code' => $rule->code,
                    'rule_group' => $rule->group->value,
                    'severity' => $rule->severity->value,
                    'status' => $rule->status->value,
                    'title_key' => $rule->titleKey,
                    'message_key' => $rule->messageKey,
                    'section' => $rule->section?->value,
                    'field' => $rule->field,
                    'safe_context' => $rule->safeContext,
                ])->save();
            }

            return $run;
        });
    }
}
