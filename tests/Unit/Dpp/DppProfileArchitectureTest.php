<?php

namespace Tests\Unit\Dpp;

use App\Enums\DppRequirementLevel;
use App\Models\DppProfile;
use App\Services\Dpp\EffectiveDppRequirementSet;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DppProfileArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_seed_versioned_pilot_and_generic_espr_profiles(): void
    {
        $this->assertDatabaseHas('dpp_profiles', [
            'key' => 'nordipass-pilot',
            'version' => 2,
        ]);
        $this->assertDatabaseHas('dpp_profiles', [
            'key' => 'generic-espr',
            'version' => 1,
            'jurisdiction' => 'EU',
        ]);
        $this->assertGreaterThan(
            0,
            DppProfile::query()->where('key', 'generic-espr')->firstOrFail()->requirements()->count(),
        );
    }

    public function test_effective_requirement_set_is_deterministic_and_rejects_conflicts(): void
    {
        $first = DppProfile::create(['key' => 'test-first', 'name' => 'First', 'version' => 1]);
        $second = DppProfile::create(['key' => 'test-second', 'name' => 'Second', 'version' => 1]);

        $attributes = [
            'title' => 'Manufacturer name',
            'description' => 'A manufacturer is identified.',
            'level' => DppRequirementLevel::Required,
            'rule_code' => 'manufacturer.name',
            'sort_order' => 1,
        ];
        $first->requirements()->create($attributes + ['key' => 'manufacturer.name']);
        $second->requirements()->create($attributes + ['key' => 'manufacturer.name']);

        $requirements = app(EffectiveDppRequirementSet::class)->resolve([$second, $first]);
        $this->assertCount(1, $requirements);
        $this->assertSame('manufacturer.name', $requirements[0]->key);

        $second->requirements()->firstOrFail()->update(['source' => 'different source']);

        $this->expectException(LogicException::class);
        app(EffectiveDppRequirementSet::class)->resolve([$first, $second]);
    }

    public function test_new_regulatory_profile_fingerprint_includes_persisted_requirement_catalog(): void
    {
        $pilot = app(ReadinessProfileRepository::class)->for('nordipass-pilot', 2);
        $generic = app(ReadinessProfileRepository::class)->for('generic-espr', 1);

        $this->assertNotSame($pilot->fingerprint, $generic->fingerprint);
        $this->assertSame(64, strlen($generic->fingerprint));
    }
}
