<?php

namespace Tests\Unit\Passports\Readiness;

use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Tests\TestCase;

class ReadinessProfileRepositoryTest extends TestCase
{
    public function test_nordipass_pilot_v1_profile_is_resolved_from_versioned_config(): void
    {
        $profile = app(ReadinessProfileRepository::class)->for('nordipass-pilot', 1);

        $this->assertSame('nordipass-pilot', $profile->code);
        $this->assertSame(1, $profile->version);
        $this->assertSame('weighted_ratio', $profile->scoreAlgorithm);
        $this->assertSame(1, $profile->scoreAlgorithmVersion);
        $this->assertSame(['blocker' => 10, 'warning' => 3, 'recommendation' => 1], $profile->weights);
        $this->assertSame(64, strlen($profile->fingerprint));
        $this->assertCount(66, $profile->ruleClasses);
    }

    public function test_fingerprint_changes_when_profile_weight_changes(): void
    {
        $original = app(ReadinessProfileRepository::class)->for('nordipass-pilot', 1)->fingerprint;

        config()->set('passport_readiness.profiles.nordipass-pilot.versions.1.weights.warning', 4);

        $changed = app(ReadinessProfileRepository::class)->for('nordipass-pilot', 1)->fingerprint;

        $this->assertNotSame($original, $changed);
    }
}
