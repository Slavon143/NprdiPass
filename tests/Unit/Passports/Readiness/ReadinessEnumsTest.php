<?php

namespace Tests\Unit\Passports\Readiness;

use App\Enums\Passports\Readiness\PassportReadinessStatus;
use App\Enums\Passports\Readiness\ReadinessRuleGroup;
use App\Enums\Passports\Readiness\ReadinessRuleStatus;
use App\Enums\Passports\Readiness\ReadinessSeverity;
use Tests\TestCase;

class ReadinessEnumsTest extends TestCase
{
    public function test_passport_readiness_status_has_three_cases(): void
    {
        $cases = PassportReadinessStatus::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(PassportReadinessStatus::NotReady, $cases);
        $this->assertContains(PassportReadinessStatus::ReadyWithWarnings, $cases);
        $this->assertContains(PassportReadinessStatus::Ready, $cases);
    }

    public function test_passport_readiness_status_values(): void
    {
        $this->assertSame('not_ready', PassportReadinessStatus::NotReady->value);
        $this->assertSame('ready_with_warnings', PassportReadinessStatus::ReadyWithWarnings->value);
        $this->assertSame('ready', PassportReadinessStatus::Ready->value);
    }

    public function test_readiness_severity_has_three_cases(): void
    {
        $cases = ReadinessSeverity::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(ReadinessSeverity::Blocker, $cases);
        $this->assertContains(ReadinessSeverity::Warning, $cases);
        $this->assertContains(ReadinessSeverity::Recommendation, $cases);
    }

    public function test_readiness_severity_values(): void
    {
        $this->assertSame('blocker', ReadinessSeverity::Blocker->value);
        $this->assertSame('warning', ReadinessSeverity::Warning->value);
        $this->assertSame('recommendation', ReadinessSeverity::Recommendation->value);
    }

    public function test_readiness_rule_status_has_three_cases(): void
    {
        $cases = ReadinessRuleStatus::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(ReadinessRuleStatus::Passed, $cases);
        $this->assertContains(ReadinessRuleStatus::Failed, $cases);
        $this->assertContains(ReadinessRuleStatus::NotApplicable, $cases);
    }

    public function test_readiness_rule_status_values(): void
    {
        $this->assertSame('passed', ReadinessRuleStatus::Passed->value);
        $this->assertSame('failed', ReadinessRuleStatus::Failed->value);
        $this->assertSame('not_applicable', ReadinessRuleStatus::NotApplicable->value);
    }

    public function test_readiness_rule_group_has_twelve_cases(): void
    {
        $cases = ReadinessRuleGroup::cases();

        $this->assertCount(12, $cases);
    }

    public function test_readiness_rule_group_values(): void
    {
        $this->assertSame('catalog', ReadinessRuleGroup::Catalog->value);
        $this->assertSame('passport', ReadinessRuleGroup::Passport->value);
        $this->assertSame('identity', ReadinessRuleGroup::Identity->value);
        $this->assertSame('manufacturer', ReadinessRuleGroup::Manufacturer->value);
        $this->assertSame('safety', ReadinessRuleGroup::Safety->value);
        $this->assertSame('recycling', ReadinessRuleGroup::Recycling->value);
        $this->assertSame('media', ReadinessRuleGroup::Media->value);
        $this->assertSame('documents', ReadinessRuleGroup::Documents->value);
        $this->assertSame('certificates', ReadinessRuleGroup::Certificates->value);
        $this->assertSame('environmental', ReadinessRuleGroup::Environmental->value);
        $this->assertSame('support', ReadinessRuleGroup::Support->value);
        $this->assertSame('technical', ReadinessRuleGroup::Technical->value);
    }

    public function test_enums_are_string_backed(): void
    {
        $this->assertTrue((new \ReflectionEnum(PassportReadinessStatus::class))->isBacked());
        $this->assertTrue((new \ReflectionEnum(ReadinessSeverity::class))->isBacked());
        $this->assertTrue((new \ReflectionEnum(ReadinessRuleStatus::class))->isBacked());
        $this->assertTrue((new \ReflectionEnum(ReadinessRuleGroup::class))->isBacked());
    }

    public function test_passport_readiness_status_from(): void
    {
        $this->assertSame(PassportReadinessStatus::NotReady, PassportReadinessStatus::from('not_ready'));
        $this->assertSame(PassportReadinessStatus::ReadyWithWarnings, PassportReadinessStatus::from('ready_with_warnings'));
        $this->assertSame(PassportReadinessStatus::Ready, PassportReadinessStatus::from('ready'));
    }

    public function test_stats_helpers_lookup(): void
    {
        $this->assertSame(PassportReadinessStatus::NotReady, PassportReadinessStatus::tryFrom('not_ready'));
        $this->assertNull(PassportReadinessStatus::tryFrom('invalid'));

        $this->assertSame(ReadinessSeverity::Blocker, ReadinessSeverity::tryFrom('blocker'));
        $this->assertNull(ReadinessSeverity::tryFrom('critical'));

        $this->assertSame(ReadinessRuleStatus::Passed, ReadinessRuleStatus::tryFrom('passed'));
        $this->assertNull(ReadinessRuleStatus::tryFrom('skipped'));

        $this->assertSame(ReadinessRuleGroup::Catalog, ReadinessRuleGroup::tryFrom('catalog'));
        $this->assertNull(ReadinessRuleGroup::tryFrom('unknown'));
    }
}
