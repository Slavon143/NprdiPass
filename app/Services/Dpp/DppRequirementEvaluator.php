<?php

namespace App\Services\Dpp;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\DppRequirementLevel;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Models\DppRequirement;

class DppRequirementEvaluator
{
    /** @param array<string, PassportReadinessRule> $registered */
    public function evaluate(DppRequirement $requirement, ReadinessEvaluationContext $context, array $registered): ReadinessRuleResult
    {
        $code = $requirement->rule_code ?? $requirement->key;
        $class = $requirement->rule_class;
        $rule = is_string($class) && is_subclass_of($class, PassportReadinessRule::class)
            ? app($class) : ($registered[$code] ?? null);
        $config = $requirement->evaluation_config ?? [];
        $canonical = $registered[$code] ?? null;
        $boundaryRule = $canonical ?? $rule;
        $integrity = $boundaryRule instanceof PassportReadinessRule
            && in_array($boundaryRule->group(), [ReadinessRuleGroup::Passport, ReadinessRuleGroup::Documents, ReadinessRuleGroup::Media], true)
            && $boundaryRule->severity() === ReadinessSeverity::Blocker;
        $error = null;
        $dateReason = $requirement->effective_from?->greaterThan($context->evaluationDate->startOfDay())
            ? 'requirement_not_yet_effective'
            : ($requirement->effective_to?->lessThan($context->evaluationDate->startOfDay()) ? 'requirement_no_longer_effective' : null);
        if (($integrity && (array_diff(array_keys($config), ['evidence']) !== [] || $requirement->effective_from !== null || $requirement->effective_to !== null
                || ($canonical !== null && $rule::class !== $canonical::class)))
            || array_diff(array_keys($config), ['when_section_enabled', 'context_config', 'evidence']) !== []
            || (array_key_exists('evidence', $config) && ! app(DppRequirementEvidence::class)->configurationValid($config['evidence']))
            || (isset($config['context_config']) && ! is_array($config['context_config']))
            || (array_key_exists('when_section_enabled', $config) && (! is_string($config['when_section_enabled'])
                || DppSectionKey::tryFrom($config['when_section_enabled']) === null))) {
            $error = 'unsupported_configuration';
        } elseif ($requirement->effective_from !== null && $requirement->effective_to !== null
            && $requirement->effective_from->greaterThan($requirement->effective_to)) {
            $error = 'invalid_application_window';
        } elseif ($dateReason === null && (! $rule instanceof PassportReadinessRule || $rule->code() !== $code
            || ($class !== null && ! is_subclass_of($class, PassportReadinessRule::class)))) {
            $error = 'missing_or_mismatched_rule';
        }

        if ($error !== null) {
            $result = new ReadinessRuleResult(
                $code, ReadinessRuleGroup::Technical, ReadinessSeverity::Blocker, ReadinessRuleStatus::Failed,
                'readiness.dpp.requirement.title', 'readiness.dpp.requirement.unavailable',
                safeContext: ['evaluation_error' => $error, 'requirement_key' => $requirement->key],
            );
        } elseif ($dateReason !== null) {
            $result = new ReadinessRuleResult(
                $code, $rule?->group() ?? ReadinessRuleGroup::Technical,
                $rule?->severity() ?? ReadinessSeverity::Blocker, ReadinessRuleStatus::NotApplicable,
                'readiness.dpp.requirement.title', 'readiness.dpp.requirement.deferred',
                safeContext: ['not_applicable_reason' => $dateReason],
            );
        } elseif (isset($config['when_section_enabled'])
            && ! in_array($config['when_section_enabled'], $context->normalizedPayload['enabled_sections'] ?? [], true)) {
            $result = new ReadinessRuleResult(
                $code, $rule->group(), $rule->severity(), ReadinessRuleStatus::NotApplicable,
                'readiness.dpp.requirement.title', 'readiness.dpp.requirement.not_applicable',
                safeContext: ['not_applicable_reason' => 'profile_section_not_enabled'],
            );
        } else {
            if (isset($config['context_config'])) {
                $attributes = get_object_vars($context);
                $attributes['config'] = array_replace_recursive($context->config, $config['context_config']);
                $context = new ReadinessEvaluationContext(...$attributes);
            }
            $result = $rule->evaluate($context);
            $evidence = app(DppRequirementEvidence::class);
            $policy = $evidence->policy($requirement, $rule->group());
            $matchedDocument = $policy === null ? null : $evidence->matchingDocument($policy, $context,
                $code === 'dpp.safety.reviewed' ? 'safety' : $requirement->section);
            if ($matchedDocument !== null) {
                $attributes = get_object_vars($result);
                $attributes['safeContext']['required_evidence_satisfied'] = true;
                $attributes['safeContext']['matched_evidence'] = [
                    'document_uuid' => $matchedDocument->uuid,
                    'version_uuid' => $matchedDocument->currentVersion->uuid,
                    'checksum_sha256' => $matchedDocument->currentVersion->checksum_sha256,
                    'reviewed_at' => $matchedDocument->currentVersion->reviewed_at?->toIso8601String(),
                    'approved_at' => $matchedDocument->currentVersion->approved_at?->toIso8601String(),
                ];
                $result = new ReadinessRuleResult(...$attributes);
            }
            $materialsMissing = $requirement->level === DppRequirementLevel::Required
                && $requirement->getRelation('profile')->key !== 'nordipass-pilot'
                && str_starts_with($code, 'dpp.materials.')
                && (! in_array('materials_and_composition', $context->normalizedPayload['enabled_sections'] ?? [], true)
                    || empty($context->normalizedPayload['data']['materials_and_composition']['materials']));
            if ($materialsMissing || ($policy !== null && $matchedDocument === null)) {
                $attributes = get_object_vars($result);
                $attributes['status'] = ReadinessRuleStatus::Failed;
                $attributes['messageKey'] = $materialsMissing ? 'readiness.dpp.requirement.materials_missing' : 'readiness.dpp.requirement.evidence_missing';
                $attributes['safeContext']['required_evidence_satisfied'] = false;
                unset($attributes['safeContext']['not_applicable_reason']);
                $attributes['navigationTarget'] = $materialsMissing
                    ? new ReadinessNavigationTarget('passport_section', 'materials_and_composition',
                        'catalog.products.passport.edit', ['product' => $context->product->uuid ?? ''], 'Edit Materials')
                    : new ReadinessNavigationTarget('product_document', null,
                        'catalog.products.documents.index', ['product' => $context->product->uuid ?? ''], 'Product documents');
                if ($materialsMissing) {
                    $attributes['section'] = DppSectionKey::MaterialsAndComposition;
                    $attributes['field'] = 'materials';
                }
                $result = new ReadinessRuleResult(...$attributes);
            }
            if ($code === 'dpp.safety.reviewed' && $policy !== null) {
                $attributes = get_object_vars($result);
                $attributes['titleKey'] = 'readiness.dpp.requirement.safety_review_title';
                if ($result->status === ReadinessRuleStatus::Passed) {
                    $attributes['messageKey'] = 'readiness.dpp.requirement.safety_review_passed';
                }
                $result = new ReadinessRuleResult(...$attributes);
            }
        }

        $severity = $error !== null || $integrity ? ReadinessSeverity::Blocker : match ($requirement->level) {
            DppRequirementLevel::Required => ReadinessSeverity::Blocker,
            DppRequirementLevel::Recommended => ReadinessSeverity::Recommendation,
            DppRequirementLevel::Conditional => $result->severity,
        };
        $attributes = get_object_vars($result);
        $attributes['severity'] = $severity;
        $attributes['safeContext'] = array_replace($result->safeContext, [
            'requirement_source_version' => $requirement->source_version,
            'requirement_effective_from' => $requirement->effective_from?->toDateString(),
            'requirement_effective_to' => $requirement->effective_to?->toDateString(),
            'applicability_checked_on' => $context->evaluationDate->toDateString(),
        ]);
        $result = new ReadinessRuleResult(...$attributes);
        $profile = $requirement->getRelation('profile');

        return $result->withRegulatoryMetadata(
            $requirement->key, $profile->key, $profile->version, $requirement->level->value,
            $requirement->source, $requirement->source_reference, $requirement->applies_because,
            $result->status === ReadinessRuleStatus::NotApplicable ? 'not_applicable' : 'applicable',
            $result->safeContext['not_applicable_reason'] ?? $requirement->applies_because,
        );
    }
}
