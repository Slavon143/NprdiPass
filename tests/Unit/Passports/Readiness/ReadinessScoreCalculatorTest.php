<?php

namespace Tests\Unit\Passports\Readiness;

use App\Data\Passports\Readiness\ReadinessRuleResult;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Services\Passports\Readiness\ReadinessScoreCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class ReadinessScoreCalculatorTest extends TestCase
{
    private ReadinessScoreCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new ReadinessScoreCalculator;
    }

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

    public function test_all_passed_returns_score_100(): void
    {
        $rules = [
            $this->makeRule('rule.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.2', ReadinessRuleGroup::Safety, ReadinessSeverity::Warning, ReadinessRuleStatus::Passed),
            $this->makeRule('rule.3', ReadinessRuleGroup::Support, ReadinessSeverity::Recommendation, ReadinessRuleStatus::Passed),
        ];

        $score = $this->calculator->calculate($rules);

        $this->assertSame(100, $score);
    }

    public function test_all_failed_returns_score_0(): void
    {
        $rules = [
            $this->makeRule('rule.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Failed),
            $this->makeRule('rule.2', ReadinessRuleGroup::Safety, ReadinessSeverity::Warning, ReadinessRuleStatus::Failed),
            $this->makeRule('rule.3', ReadinessRuleGroup::Support, ReadinessSeverity::Recommendation, ReadinessRuleStatus::Failed),
        ];

        $score = $this->calculator->calculate($rules);

        $this->assertSame(0, $score);
    }

    public function test_mixed_rules_respect_weights(): void
    {
        $rules = [
            $this->makeRule('blocker.passed', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('blocker.failed', ReadinessRuleGroup::Safety, ReadinessSeverity::Blocker, ReadinessRuleStatus::Failed),
            $this->makeRule('warning.passed', ReadinessRuleGroup::Identity, ReadinessSeverity::Warning, ReadinessRuleStatus::Passed),
            $this->makeRule('warning.failed', ReadinessRuleGroup::Recycling, ReadinessSeverity::Warning, ReadinessRuleStatus::Failed),
            $this->makeRule('rec.passed', ReadinessRuleGroup::Support, ReadinessSeverity::Recommendation, ReadinessRuleStatus::Passed),
            $this->makeRule('rec.failed', ReadinessRuleGroup::Technical, ReadinessSeverity::Recommendation, ReadinessRuleStatus::Failed),
        ];

        $passedWeight = 10 + 3 + 1;
        $totalWeight = 10 + 10 + 3 + 3 + 1 + 1;
        $expectedScore = (int) round($passedWeight / $totalWeight * 100);

        $score = $this->calculator->calculate($rules);

        $this->assertSame($expectedScore, $score);
    }

    public function test_not_applicable_rules_are_excluded(): void
    {
        $rules = [
            $this->makeRule('passed', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('failed', ReadinessRuleGroup::Safety, ReadinessSeverity::Blocker, ReadinessRuleStatus::Failed),
            $this->makeRule('not.applicable', ReadinessRuleGroup::Support, ReadinessSeverity::Blocker, ReadinessRuleStatus::NotApplicable),
        ];

        $score = $this->calculator->calculate($rules);

        $this->assertSame(50, $score);
    }

    public function test_no_applicable_rules_returns_score_100(): void
    {
        $rules = [
            $this->makeRule('not.applicable.1', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::NotApplicable),
            $this->makeRule('not.applicable.2', ReadinessRuleGroup::Safety, ReadinessSeverity::Warning, ReadinessRuleStatus::NotApplicable),
        ];

        $score = $this->calculator->calculate($rules);

        $this->assertSame(100, $score);
    }

    public function test_empty_rules_returns_score_100(): void
    {
        $score = $this->calculator->calculate([]);

        $this->assertSame(100, $score);
    }

    public function test_score_is_clamped_between_0_and_100(): void
    {
        $rules = [
            $this->makeRule('passed', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
        ];

        $score = $this->calculator->calculate($rules);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
        $this->assertSame(100, $score);

        $failedRules = [
            $this->makeRule('failed', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Failed),
        ];

        $score = $this->calculator->calculate($failedRules);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
        $this->assertSame(0, $score);
    }

    public function test_rounding_behavior(): void
    {
        $rules = [
            $this->makeRule('blocker.passed', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('warning.passed', ReadinessRuleGroup::Identity, ReadinessSeverity::Warning, ReadinessRuleStatus::Passed),
            $this->makeRule('rec.failed', ReadinessRuleGroup::Support, ReadinessSeverity::Recommendation, ReadinessRuleStatus::Failed),
        ];

        $score = $this->calculator->calculate($rules);

        // Passed: 10+3=13, Total: 10+3+1=14 => 13/14*100 = 92.857... rounds to 93
        $this->assertSame(93, $score);
    }

    public function test_weight_config_defaults_are_used(): void
    {
        // Depends on active readiness profile v1 weights: blocker=10, warning=3, recommendation=1
        $rules = [
            $this->makeRule('blocker.passed', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('blocker.failed', ReadinessRuleGroup::Safety, ReadinessSeverity::Blocker, ReadinessRuleStatus::Failed),
        ];

        $score = $this->calculator->calculate($rules);

        $this->assertSame(50, $score);
    }

    public function test_multiple_not_applicable_mixed_with_applicable(): void
    {
        $rules = [
            $this->makeRule('blocker.passed', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('not.applicable.1', ReadinessRuleGroup::Support, ReadinessSeverity::Blocker, ReadinessRuleStatus::NotApplicable),
            $this->makeRule('not.applicable.2', ReadinessRuleGroup::Technical, ReadinessSeverity::Blocker, ReadinessRuleStatus::NotApplicable),
            $this->makeRule('warning.failed', ReadinessRuleGroup::Identity, ReadinessSeverity::Warning, ReadinessRuleStatus::Failed),
        ];

        $score = $this->calculator->calculate($rules);

        $passedWeight = 10;
        $totalWeight = 10 + 3;
        $expected = (int) round($passedWeight / $totalWeight * 100);

        $this->assertSame($expected, $score);
        $this->assertSame(77, $score);
    }

    public function test_score_and_breakdown_are_independent_of_rule_order(): void
    {
        $rules = [
            $this->makeRule('a', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed),
            $this->makeRule('b', ReadinessRuleGroup::Safety, ReadinessSeverity::Warning, ReadinessRuleStatus::Failed),
            $this->makeRule('c', ReadinessRuleGroup::Support, ReadinessSeverity::Recommendation, ReadinessRuleStatus::NotApplicable),
        ];

        $forward = $this->calculator->breakdown($rules);
        $reverse = $this->calculator->breakdown(array_reverse($rules));

        $this->assertSame($forward->toArray(), $reverse->toArray());
        $this->assertSame(10, $forward->earnedPoints);
        $this->assertSame(13, $forward->applicablePoints);
    }

    public function test_duplicate_rule_codes_are_rejected_instead_of_counted_twice(): void
    {
        $rule = $this->makeRule('duplicate', ReadinessRuleGroup::Catalog, ReadinessSeverity::Blocker, ReadinessRuleStatus::Passed);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate readiness rule code: duplicate.');

        $this->calculator->calculate([$rule, $rule]);
    }

    public function test_missing_or_invalid_weight_is_rejected(): void
    {
        config()->set('passport_readiness.profiles.nordipass-pilot.versions.1.weights.warning', 0);
        $rule = $this->makeRule('warning', ReadinessRuleGroup::Safety, ReadinessSeverity::Warning, ReadinessRuleStatus::Passed);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Readiness score weight for warning must be a positive integer.');

        $this->calculator->calculate([$rule]);
    }
}
