<?php

namespace App\Actions\Passports;

use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Enums\CompanyStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\PassportValidationResult;
use App\Models\Passports\PassportValidationRun;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Billing\CommercialAccessPolicy;
use App\Services\Catalog\ManagedProductGate;
use App\Services\Passports\CanonicalJsonEncoder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RecordPassportValidationRun
{
    public function __construct(
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
            $currentCompany = Company::query()->whereKey($company->getKey())->sharedLock()->firstOrFail();
            if ($currentCompany->status !== CompanyStatus::Active) {
                throw new AuthorizationException;
            }
            app(CommercialAccessPolicy::class)->assertWritable($currentCompany);
            $currentPassport = ProductPassport::query()->forCompany($currentCompany)->whereKey($passport->getKey())->sharedLock()->firstOrFail();
            if ((int) $draft->company_id !== (int) $currentCompany->getKey()
                || (int) $draft->passport_id !== (int) $currentPassport->getKey()) {
                throw new NotFoundHttpException;
            }
            $currentProduct = Product::query()->forCompany($currentCompany)
                ->whereKey($currentPassport->product_id)->sharedLock()->firstOrFail();
            app(ManagedProductGate::class)->assertManaged($currentProduct);

            $breakdown = $result->scoreBreakdown;
            $sourceChecksum = $this->canonicalJsonEncoder->hash([
                'passport_uuid' => $passport->uuid,
                'draft_version_uuid' => $draft->uuid,
                'draft_revision' => $draft->draft_revision,
                'schema_version' => $draft->schema_version,
                'profile' => $result->profile,
                'profile_version' => $result->profileVersion,
                'rule_set_version' => $result->ruleSetVersion,
                'rule_set_fingerprint' => $result->ruleSetFingerprint,
                'score_algorithm' => $result->scoreAlgorithm,
                'score_algorithm_version' => $result->scoreAlgorithmVersion,
                'weights' => $breakdown->weights,
                'payload' => $draft->payload,
                'profile_details' => $result->profileDetails,
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
                'rule_set_version' => $result->ruleSetVersion,
                'score_algorithm' => $result->scoreAlgorithm,
                'score_algorithm_version' => $result->scoreAlgorithmVersion,
                'rule_set_fingerprint' => $result->ruleSetFingerprint,
                'weights_snapshot' => $breakdown->weights,
                'profile_snapshot' => [
                    'profile' => $result->profile,
                    'profile_version' => $result->profileVersion,
                    'rule_set_version' => $result->ruleSetVersion,
                    'score_algorithm' => $result->scoreAlgorithm,
                    'score_algorithm_version' => $result->scoreAlgorithmVersion,
                    'rule_set_fingerprint' => $result->ruleSetFingerprint,
                    'weights' => $breakdown->weights,
                    'profile_details' => $result->profileDetails,
                    'results' => array_map(fn ($rule) => get_object_vars($rule), $result->rules),
                ],
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
                    'configured_weight' => $breakdown->weights[$rule->severity->value],
                    'status' => $rule->status->value,
                    'title_key' => $rule->titleKey,
                    'message_key' => $rule->messageKey,
                    'section' => $rule->section?->value,
                    'field' => $rule->field,
                    'source_type' => $rule->group->value,
                    'source_field' => $rule->field,
                    'fix_action_snapshot' => $rule->navigationTarget === null ? null : [
                        'type' => $rule->navigationTarget->type,
                        'section' => $rule->navigationTarget->section,
                        'route_name' => $rule->navigationTarget->routeName,
                        'route_parameters' => $rule->navigationTarget->routeParameters,
                        'label' => $rule->navigationTarget->label,
                    ],
                    'safe_context' => $rule->safeContext,
                ])->save();
            }

            return $run;
        });
    }
}
