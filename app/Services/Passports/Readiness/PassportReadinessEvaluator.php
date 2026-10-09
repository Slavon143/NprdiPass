<?php

namespace App\Services\Passports\Readiness;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Data\Passports\Readiness\ReadinessSummary;
use App\Enums\Passports\Readiness\PassportReadinessStatus;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Models\DppProfile;
use App\Models\DppRequirement;
use App\Services\Dpp\DppRegulatoryScope;
use App\Services\Dpp\DppRequirementEvaluator;
use App\Services\Dpp\ReadinessExecutionProvenance;

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
        $executedRules = $rules;
        foreach ($context->effectiveRequirements as $requirement) {
            if (is_string($requirement->rule_class) && is_subclass_of($requirement->rule_class, PassportReadinessRule::class)) {
                $executedRules[] = app($requirement->rule_class);
            }
        }
        $execution = app(ReadinessExecutionProvenance::class)->capture($executedRules);
        $draftExecution = $context->currentDraft?->readiness_profile_snapshot['execution'] ?? null;
        $ruleResults = [];
        $requirements = [];
        foreach ($context->effectiveRequirements as $requirement) {
            $requirements[$requirement->rule_code ?? $requirement->key] = $requirement;
        }
        $profileModel = $context->dppProfiles[0] ?? null;

        $registered = [];
        foreach ($rules as $rule) {
            $registered[$rule->code()] = $rule;
        }
        $requirementCodes = array_map(fn ($requirement) => $requirement->rule_code ?? $requirement->key, $context->effectiveRequirements);

        foreach ($rules as $rule) {
            if ($context->effectiveRequirements !== [] && (in_array($rule->code(), $requirementCodes, true)
                || ! in_array($rule->group(), [ReadinessRuleGroup::Passport, ReadinessRuleGroup::Documents, ReadinessRuleGroup::Media], true))) {
                continue;
            }
            $result = $rule->evaluate($context);
            $requirement = $requirements[$result->code] ?? null;
            $applicability = $result->status === ReadinessRuleStatus::NotApplicable
                ? 'not_applicable'
                : 'applicable';
            $requirementKey = $requirement instanceof DppRequirement ? $requirement->key : $result->code;
            $requirementLevel = $requirement instanceof DppRequirement
                ? $requirement->level->value
                : match ($result->severity) {
                    ReadinessSeverity::Blocker => 'required',
                    ReadinessSeverity::Recommendation => 'recommended',
                    default => 'conditional',
                };

            $ruleResults[] = $result->withRegulatoryMetadata(
                requirementKey: $requirementKey,
                profile: $profile->code,
                profileVersion: $profile->version,
                requirementLevel: $requirement instanceof DppRequirement ? $requirementLevel : $requirementLevel,
                source: $requirement instanceof DppRequirement ? $requirement->source : null,
                sourceReference: $requirement instanceof DppRequirement ? $requirement->source_reference : null,
                appliesBecause: $requirement instanceof DppRequirement ? $requirement->applies_because : null,
                applicability: $applicability,
                applicabilityReason: $result->safeContext['not_applicable_reason']
                    ?? ($requirement instanceof DppRequirement ? $requirement->applies_because : null),
            );
        }

        foreach ($context->effectiveRequirements as $requirement) {
            $result = app(DppRequirementEvaluator::class)->evaluate($requirement, $context, $registered);
            if (array_any($ruleResults, fn ($existing) => $existing->code === $result->code)) {
                $attributes = get_object_vars($result);
                $attributes['code'] = mb_substr($result->code, 0, 80).'#'.substr(hash('sha256', $requirement->key), 0, 12);
                $result = new ReadinessRuleResult(...$attributes);
            }
            $ruleResults[] = $result;
        }

        if (($context->publicationProfileCheck['status'] ?? null) === 'stale') {
            $ruleResults[] = new ReadinessRuleResult(
                'regulatory.profile.current', ReadinessRuleGroup::Technical, ReadinessSeverity::Blocker,
                ReadinessRuleStatus::Failed, 'readiness.regulatory.profile.title', 'readiness.regulatory.profile.stale',
                safeContext: ['reason' => $context->publicationProfileCheck['reason']],
            );
        }
        $scope = app(DppRegulatoryScope::class)->assess(
            $context->normalizedPayload['regulatory_context'] ?? null, $context->evaluationDate,
        );
        if (in_array($scope['status'], ['mandatory', 'invalid', 'assessment_incomplete'], true)) {
            // Identifying Article 77 scope is not implementation of Annex XIII.
            // Keep this independent of selected profile and warning acknowledgement.
            $ruleResults[] = new ReadinessRuleResult(
                'regulatory.battery.coverage', ReadinessRuleGroup::Technical, ReadinessSeverity::Blocker,
                ReadinessRuleStatus::Failed, 'readiness.regulatory.battery.title',
                match ($scope['status']) {
                    'invalid' => 'readiness.regulatory.context.invalid',
                    'assessment_incomplete' => 'readiness.regulatory.context.date_missing',
                    default => 'readiness.regulatory.battery.incomplete',
                },
                safeContext: $scope,
            );
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
            evaluatedAt: $context->evaluationDate,
            profileDetails: [
                'publication_profile_check' => $context->publicationProfileCheck,
                'regulatory_scope' => $scope,
                'execution' => $execution,
                'draft_execution' => $draftExecution,
                'execution_comparison' => ! is_array($draftExecution) ? 'legacy_unknown'
                    : (($draftExecution['fingerprint'] ?? null) === $execution['fingerprint'] ? 'matched' : 'changed'),
                'document_versions' => array_map(fn ($document) => [
                    'document_uuid' => $document->uuid, 'version_uuid' => $document->currentVersion?->uuid,
                    'checksum_sha256' => $document->currentVersion?->checksum_sha256,
                ], $context->referencedDocuments),
                'name' => $profileModel instanceof DppProfile ? $profileModel->name : $profile->name,
                'description' => $profileModel instanceof DppProfile ? $profileModel->description : $profile->description,
                'jurisdiction' => $profileModel instanceof DppProfile
                    ? $profileModel->jurisdiction
                    : ($profile->metadata['jurisdiction'] ?? null),
                'product_scope' => $profileModel instanceof DppProfile ? $profileModel->product_scope : null,
                'metadata' => $profileModel instanceof DppProfile
                    ? $profileModel->metadata
                    : $profile->metadata,
                'requirements' => array_map(fn ($requirement) => $requirement->attributesToArray(), $context->effectiveRequirements),
                'profiles' => array_map(fn ($selected) => $selected->attributesToArray(), $context->dppProfiles),
                'evaluation_config' => $context->config,
            ],
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
