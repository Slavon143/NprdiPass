<?php

namespace App\Services\Passports\Readiness;

use App\Data\Passports\Readiness\PassportReadinessResult;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Data\Passports\Readiness\ReadinessScoreBreakdown;
use App\Data\Passports\Readiness\ReadinessSummary;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\Readiness\PassportReadinessStatus;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\PassportValidationRun;
use App\Models\Passports\ProductPassport;

class StoredPassportReadiness
{
    public function latest(Company $company, Product $product): ?PassportReadinessResult
    {
        $passport = ProductPassport::query()->forCompany($company)->where('product_id', $product->getKey())->first();
        if ($passport === null) {
            return null;
        }
        $run = PassportValidationRun::query()->where('company_id', $company->getKey())
            ->where('passport_id', $passport->getKey())->latest('id')->first();
        if ($run === null) {
            return null;
        }
        $draft = $run->draftVersion()->where('company_id', $company->getKey())->first();
        if ($draft === null) {
            return null;
        }
        $snapshot = is_array($run->profile_snapshot) ? $run->profile_snapshot : [];
        $provenance = [];
        foreach ($snapshot['results'] ?? [] as $record) {
            if (is_array($record) && is_string($record['code'] ?? null)) {
                $provenance[$record['code']] = $record;
            }
        }
        $rules = [];
        $failedPoints = ['blocker' => 0, 'warning' => 0, 'recommendation' => 0];
        $weights = ['blocker' => (int) ($run->weights_snapshot['blocker'] ?? 0),
            'warning' => (int) ($run->weights_snapshot['warning'] ?? 0),
            'recommendation' => (int) ($run->weights_snapshot['recommendation'] ?? 0)];
        foreach ($run->results()->where('company_id', $company->getKey())->orderBy('id')->get() as $record) {
            $metadata = $provenance[$record->code] ?? [];
            $severity = ReadinessSeverity::from((string) $record->severity);
            $status = ReadinessRuleStatus::from((string) $record->status);
            if ($status === ReadinessRuleStatus::Failed) {
                $failedPoints[$severity->value] += (int) $record->configured_weight;
            }
            $rules[] = (new ReadinessRuleResult(
                code: (string) $record->code,
                group: ReadinessRuleGroup::from((string) $record->rule_group),
                severity: $severity, status: $status,
                titleKey: (string) $record->title_key, messageKey: (string) $record->message_key,
                section: is_string($record->section) ? DppSectionKey::tryFrom($record->section) : null,
                field: $record->field, safeContext: $record->safe_context ?? [],
            ))->withRegulatoryMetadata(
                $metadata['requirementKey'] ?? null, $metadata['profile'] ?? null,
                isset($metadata['profileVersion']) ? (int) $metadata['profileVersion'] : null,
                $metadata['requirementLevel'] ?? null, $metadata['source'] ?? null,
                $metadata['sourceReference'] ?? null, $metadata['appliesBecause'] ?? null,
                $metadata['applicability'] ?? 'applicable', $metadata['applicabilityReason'] ?? null,
            );
        }

        return new PassportReadinessResult(
            profile: (string) $run->profile, profileVersion: (int) $run->profile_version,
            schemaVersion: (int) $run->schema_version, passportUuid: $passport->uuid, draftVersionUuid: $draft->uuid,
            passportRevision: (int) $run->draft_revision, ruleSetVersion: (int) $run->rule_set_version,
            scoreAlgorithm: (string) $run->score_algorithm, scoreAlgorithmVersion: (int) $run->score_algorithm_version,
            ruleSetFingerprint: (string) $run->rule_set_fingerprint,
            status: PassportReadinessStatus::from((string) $run->status), score: (int) $run->score,
            scoreBreakdown: new ReadinessScoreBreakdown((int) $run->score, (int) $run->earned_points,
                (int) $run->applicable_points, (int) $run->not_applicable_count, $weights, $failedPoints),
            counts: new ReadinessSummary((int) $run->passed_count, (int) $run->blocker_count,
                (int) $run->warning_count, (int) $run->recommendation_count, (int) $run->not_applicable_count),
            rules: $rules, evaluatedAt: $run->validated_at, profileDetails: $snapshot['profile_details'] ?? [],
        );
    }
}
