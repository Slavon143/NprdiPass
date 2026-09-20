<?php

namespace App\Services\Passports\Readiness\Rules;

use App\Contracts\Passports\PassportReadinessRule;
use App\Data\Passports\Readiness\ReadinessEvaluationContext;
use App\Data\Passports\Readiness\ReadinessNavigationTarget;
use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;

class DppEnvironmentalClaimsReview implements PassportReadinessRule
{
    public function code(): string
    {
        return 'dpp.environmental.claims.review';
    }

    public function group(): ReadinessRuleGroup
    {
        return ReadinessRuleGroup::Environmental;
    }

    public function severity(): ReadinessSeverity
    {
        return ReadinessSeverity::Recommendation;
    }

    public function evaluate(ReadinessEvaluationContext $context): ReadinessRuleResult
    {
        $enabledSections = $context->normalizedPayload['enabled_sections'] ?? [];

        if (! in_array(DppSectionKey::EnvironmentalInformation->value, $enabledSections, true)) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.environmental.claims.review.title',
                messageKey: 'readiness.dpp.environmental.claims.review.not_applicable',
                safeContext: ['section_enabled' => false],
            );
        }

        $section = $context->sectionData(DppSectionKey::EnvironmentalInformation->value);
        $claims = $section['environmental_claims'] ?? [];
        $records = $section['environmental_claim_records'] ?? [];
        $hasClaims = (is_array($claims) && $claims !== []) || (is_array($records) && $records !== []);

        if (! $hasClaims) {
            return new ReadinessRuleResult(
                code: $this->code(),
                group: $this->group(),
                severity: $this->severity(),
                status: ReadinessRuleStatus::NotApplicable,
                titleKey: 'readiness.dpp.environmental.claims.review.title',
                messageKey: 'readiness.dpp.environmental.claims.review.not_applicable_empty',
                safeContext: ['claims_present' => false],
            );
        }

        $allReviewed = is_array($records) && $records !== [];
        foreach (is_array($records) ? $records : [] as $record) {
            if (! is_array($record) || ($record['review_state'] ?? null) !== 'reviewed') {
                $allReviewed = false;
                break;
            }
        }

        return new ReadinessRuleResult(
            code: $this->code(),
            group: $this->group(),
            severity: $this->severity(),
            status: $allReviewed ? ReadinessRuleStatus::Passed : ReadinessRuleStatus::Failed,
            titleKey: 'readiness.dpp.environmental.claims.review.title',
            messageKey: $allReviewed
                ? 'readiness.dpp.environmental.claims.review.passed'
                : 'readiness.dpp.environmental.claims.review.recommendation',
            section: DppSectionKey::EnvironmentalInformation,
            field: 'environmental_claim_records',
            navigationTarget: $allReviewed ? null : new ReadinessNavigationTarget(
                type: 'passport_section',
                section: DppSectionKey::EnvironmentalInformation->value,
                routeName: 'catalog.products.passport.edit',
                routeParameters: ['product' => $context->product->uuid ?? ''],
                label: 'Review Environmental Claims',
            ),
            safeContext: [
                'claims_present' => true,
                'structured_claims_count' => is_array($records) ? count($records) : 0,
                'all_claims_reviewed' => $allReviewed,
            ],
        );
    }
}
