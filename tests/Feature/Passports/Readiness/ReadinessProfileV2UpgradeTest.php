<?php

namespace Tests\Feature\Passports\Readiness;

use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadinessProfileV2UpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_moves_only_editable_drafts_to_profile_v2(): void
    {
        $passport = ProductPassport::factory()->create();
        $attributes = [
            'company_id' => $passport->company_id,
            'passport_id' => $passport->getKey(),
            'readiness_profile' => 'nordipass-pilot',
            'readiness_profile_version' => 1,
            'readiness_rule_set_fingerprint' => 'f668cbb32defc4b23420a129970ec9233c8cb330905898ce2206e37583611569',
        ];

        $draft = ProductPassportVersion::factory()->draft()->create($attributes);
        $published = ProductPassportVersion::factory()->published()->create($attributes);

        $migration = require database_path('migrations/2026_08_20_000001_upgrade_editable_passport_drafts_to_readiness_profile_v2.php');
        $migration->up();

        $draft->refresh();
        $published->refresh();

        $this->assertSame(2, $draft->readiness_profile_version);
        $this->assertSame(
            'cba5078e465bba66f53ffcb613a7975a9ca4d5c8dd7888c72dcac988bd95e5b5',
            $draft->readiness_rule_set_fingerprint,
        );
        $this->assertSame(1, $published->readiness_profile_version);
        $this->assertSame(
            'f668cbb32defc4b23420a129970ec9233c8cb330905898ce2206e37583611569',
            $published->readiness_rule_set_fingerprint,
        );
    }
}
