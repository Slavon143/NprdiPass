<?php

namespace Tests\Unit\Passports\Readiness;

use App\Contracts\Passports\PassportReadinessRule;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use App\Services\Passports\Readiness\PassportReadinessRuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadinessRuleRegistryTest extends TestCase
{
    use RefreshDatabase;

    private PassportReadinessRuleRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new PassportReadinessRuleRegistry;
    }

    public function test_all_64_rules_registered(): void
    {
        $rules = $this->registry->all();

        $this->assertCount(64, $rules);
    }

    public function test_all_rule_codes_are_unique(): void
    {
        $rules = $this->registry->all();
        $codes = array_map(fn (PassportReadinessRule $rule) => $rule->code(), $rules);

        $this->assertCount(64, $codes, 'Should have 64 rule codes');
        $this->assertSameSize($rules, array_unique($codes), 'All rule codes must be unique');
    }

    public function test_all_rules_have_severity(): void
    {
        $rules = $this->registry->all();
        $validSeverities = array_map(fn (ReadinessSeverity $s) => $s->value, ReadinessSeverity::cases());

        foreach ($rules as $rule) {
            $severity = $rule->severity();
            $this->assertNotNull($severity);
            $this->assertContains($severity->value, $validSeverities, "Rule {$rule->code()} has invalid severity");
        }
    }

    public function test_all_rules_have_group(): void
    {
        $rules = $this->registry->all();
        $validGroups = array_map(fn (ReadinessRuleGroup $g) => $g->value, ReadinessRuleGroup::cases());

        foreach ($rules as $rule) {
            $group = $rule->group();
            $this->assertNotNull($group);
            $this->assertContains($group->value, $validGroups, "Rule {$rule->code()} has invalid group");
        }
    }

    public function test_rules_are_sorted_by_group_then_code(): void
    {
        $rules = $this->registry->all();

        for ($i = 1; $i < count($rules); $i++) {
            $prev = $rules[$i - 1];
            $curr = $rules[$i];

            $groupCompare = $prev->group()->value <=> $curr->group()->value;

            if ($groupCompare !== 0) {
                $this->assertLessThan(0, $groupCompare, "Rules not sorted by group: {$prev->code()} ({$prev->group()->value}) before {$curr->code()} ({$curr->group()->value})");
            } else {
                $this->assertLessThanOrEqual(0, $prev->code() <=> $curr->code(), "Rules not sorted by code within group {$prev->group()->value}: {$prev->code()} before {$curr->code()}");
            }
        }
    }

    public function test_all_rules_implement_interface(): void
    {
        $rules = $this->registry->all();

        foreach ($rules as $rule) {
            $this->assertInstanceOf(PassportReadinessRule::class, $rule);
        }
    }

    public function test_all_rules_have_code_method(): void
    {
        $rules = $this->registry->all();

        foreach ($rules as $rule) {
            $code = $rule->code();
            $this->assertIsString($code);
            $this->assertNotEmpty($code, 'Rule code must not be empty');
        }
    }

    public function test_rules_have_expected_groups(): void
    {
        $rules = $this->registry->all();
        $groups = array_map(fn (PassportReadinessRule $rule) => $rule->group()->value, $rules);
        $groupCounts = array_count_values($groups);

        $this->assertArrayHasKey('catalog', $groupCounts);
        $this->assertArrayHasKey('passport', $groupCounts);
        $this->assertArrayHasKey('identity', $groupCounts);
        $this->assertArrayHasKey('manufacturer', $groupCounts);
        $this->assertArrayHasKey('safety', $groupCounts);
        $this->assertArrayHasKey('recycling', $groupCounts);
        $this->assertArrayHasKey('media', $groupCounts);
        $this->assertArrayHasKey('documents', $groupCounts);
        $this->assertArrayHasKey('certificates', $groupCounts);
        $this->assertArrayHasKey('environmental', $groupCounts);
        $this->assertArrayHasKey('support', $groupCounts);
        $this->assertArrayHasKey('technical', $groupCounts);

        $totalFromGroups = array_sum($groupCounts);
        $this->assertSame(64, $totalFromGroups);
    }

    public function test_registry_returns_array(): void
    {
        $rules = $this->registry->all();

        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);
    }
}
