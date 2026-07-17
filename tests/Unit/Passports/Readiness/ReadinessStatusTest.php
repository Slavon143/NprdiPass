<?php

namespace Tests\Unit\Passports\Readiness;

use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\PassportReadinessStatus;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use Tests\TestCase;

class ReadinessStatusTest extends TestCase
{
    private function makeRule(string $code, ReadinessRuleGroup $group, ReadinessSeverity $severity, ReadinessRuleStatus $status): ReadinessRuleResult
    {
        return new ReadinessRuleResult(
            code: $code,
            group: $group,
            severity: $severity,
            status: $status,
            titleKey: 'readiness.'.$code.'.title',
            messageKey: 'readiness.'.$code.'.passed',
        );
    }

    public function test_failed_blocker_returns_not_ready(): void
    {
        $rules = [
            $this->makeRule('rule.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.2', ReadinessRuleGroup::Passport, ReadinessSeverity::Blocker, ReadinessRuleStatus::Failed),
        ];

        $status = PassportReadinessEvaluator::determineStatus($rules);

        $this->assertSame(PassportReadinessStatus::NotReady, $status);
    }

    public function test_failed_warnings_only_returns_ready_with_warnings(): void
    {
        $rules = [
            $this->makeRule('rule.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.2', ReadinessRuleGroup::Identity, ReadinessSeverity::Warning, ReadinessRuleStatus::Failed),
            $this->makeRule('rule.3', ReadinessRuleGroup::Support, ReadinessSeverity::Warning, ReadinessRuleStatus::Failed),
        ];

        $status = PassportReadinessEvaluator::determineStatus($rules);

        $this->assertSame(PassportReadinessStatus::ReadyWithWarnings, $status);
    }

    public function test_recommendations_only_no_blockers_or_warnings_returns_ready(): void
    {
        $rules = [
            $this->makeRule('rule.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.2', ReadinessRuleGroup::Identity, ReadinessSeverity::Warning, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.3', ReadinessRuleGroup::Support, ReadinessSeverity::Recommendation, ReadinessRuleStatus::Failed),
            $this->makeRule('rule.4', ReadinessRuleGroup::Technical, ReadinessSeverity::Recommendation, ReadinessRuleStatus::Failed),
        ];

        $status = PassportReadinessEvaluator::determineStatus($rules);

        $this->assertSame(PassportReadinessStatus::Ready, $status);
    }

    public function test_all_passed_returns_ready(): void
    {
        $rules = [
            $this->makeRule('rule.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.2', ReadinessRuleGroup::Safety, ReadinessSeverity::Warning, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.3', ReadinessRuleGroup::Support, ReadinessSeverity::Recommendation, ReadinessRuleStatus::Passed),
        ];

        $status = PassportReadinessEvaluator::determineStatus($rules);

        $this->assertSame(PassportReadinessStatus::Ready, $status);
    }

    public function test_not_applicable_rules_only_returns_ready(): void
    {
        $rules = [
            $this->makeRule('rule.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::NotApplicable),
            $this->makeRule('rule.2', ReadinessRuleGroup::Safety, ReadinessSeverity::Warning, ReadinessRuleStatus::NotApplicable),
            $this->makeRule('rule.3', ReadinessRuleGroup::Support, ReadinessSeverity::Recommendation, ReadinessRuleStatus::NotApplicable),
        ];

        $status = PassportReadinessEvaluator::determineStatus($rules);

        $this->assertSame(PassportReadinessStatus::Ready, $status);
    }

    public function test_empty_rules_returns_ready(): void
    {
        $status = PassportReadinessEvaluator::determineStatus([]);

        $this->assertSame(PassportReadinessStatus::Ready, $status);
    }

    public function test_multiple_blockers_one_failed_returns_not_ready(): void
    {
        $rules = [
            $this->makeRule('rule.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.2', ReadinessRuleGroup::Passport, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.3', ReadinessRuleGroup::Safety, ReadinessSeverity::Blocker, ReadinessRuleStatus::Failed),
        ];

        $status = PassportReadinessEvaluator::determineStatus($rules);

        $this->assertSame(PassportReadinessStatus::NotReady, $status);
    }

    public function test_blocker_failed_takes_priority_over_warning_failed(): void
    {
        $rules = [
            $this->makeRule('rule.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Failed),
            $this->makeRule('rule.2', ReadinessRuleGroup::Identity, ReadinessSeverity::Warning, ReadinessRuleStatus::Failed),
            $this->makeRule('rule.3', ReadinessRuleGroup::Support, ReadinessSeverity::Recommendation, ReadinessRuleStatus::Failed),
        ];

        $status = PassportReadinessEvaluator::determineStatus($rules);

        $this->assertSame(PassportReadinessStatus::NotReady, $status);
    }

    public function test_mixed_passed_and_passed_with_not_applicable_returns_ready(): void
    {
        $rules = [
            $this->makeRule('rule.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.2', ReadinessRuleGroup::Passport, ReadinessSeverity::Blocker, ReadinessRuleStatus::NotApplicable),
            $this->makeRule('rule.3', ReadinessRuleGroup::Identity, ReadinessSeverity::Warning, ReadinessRuleStatus::Passed),
        ];

        $status = PassportReadinessEvaluator::determineStatus($rules);

        $this->assertSame(PassportReadinessStatus::Ready, $status);
    }

    public function test_status_enum_values_are_correct(): void
    {
        $this->assertSame('not_ready', PassportReadinessStatus::NotReady->value);
        $this->assertSame('ready_with_warnings', PassportReadinessStatus::ReadyWithWarnings->value);
        $this->assertSame('ready', PassportReadinessStatus::Ready->value);
    }
}
