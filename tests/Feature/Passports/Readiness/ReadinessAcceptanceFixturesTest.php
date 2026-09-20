<?php

namespace Tests\Feature\Passports\Readiness;

use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Product;
use App\Services\Passports\Readiness\PassportReadinessEvaluator;
use App\Services\Passports\Readiness\ReadinessContextBuilder;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Database\Seeders\ReadinessAcceptanceFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadinessAcceptanceFixturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_acceptance_fixtures_match_active_v2_arithmetic(): void
    {
        $this->seed(ReadinessAcceptanceFixtureSeeder::class);

        $this->assertFixtureReadiness(
            productName: 'Reflective Safety Vest',
            expected: [
                'score' => 81,
                'earned_points' => 336,
                'applicable_points' => 414,
                'failed_points' => 78,
                'failed_points_by_severity' => [
                    'blocker' => 30,
                    'warning' => 48,
                    'recommendation' => 0,
                ],
                'passed' => 42,
                'blockers' => 3,
                'warnings' => 16,
                'recommendations' => 0,
                'not_applicable' => 5,
            ],
        );

        $traffic = Product::query()
            ->where('slug_normalized', ReadinessAcceptanceFixtureSeeder::TRAFFIC_SIGNALS_SLUG)
            ->firstOrFail();

        $this->assertSame('Traffic signals', $traffic->name);
        $this->assertSame(ProductStatus::Archived, $traffic->status);
        $this->assertNull($traffic->default_variant_id);
        $this->assertNull($traffic->primary_media_id);
        $this->assertTrue($traffic->categories()->exists());
        $this->assertFalse($traffic->variants()->exists());
        $this->assertFalse($traffic->media()->exists());

        $this->assertFixtureReadiness(
            productName: 'Traffic signals',
            expected: [
                'score' => 67,
                'earned_points' => 277,
                'applicable_points' => 414,
                'failed_points' => 137,
                'failed_points_by_severity' => [
                    'blocker' => 80,
                    'warning' => 57,
                    'recommendation' => 0,
                ],
                'passed' => 34,
                'blockers' => 8,
                'warnings' => 19,
                'recommendations' => 0,
                'not_applicable' => 5,
            ],
        );
    }

    /**
     * @param  array{
     *     score: int,
     *     earned_points: int,
     *     applicable_points: int,
     *     failed_points: int,
     *     failed_points_by_severity: array{blocker: int, warning: int, recommendation: int},
     *     passed: int,
     *     blockers: int,
     *     warnings: int,
     *     recommendations: int,
     *     not_applicable: int
     * }  $expected
     */
    private function assertFixtureReadiness(string $productName, array $expected): void
    {
        $product = Product::query()
            ->with(['company', 'passport.currentDraftVersion'])
            ->where('name', $productName)
            ->firstOrFail();

        $profile = app(ReadinessProfileRepository::class)->active();
        $draft = $product->passport->currentDraftVersion;

        $this->assertSame($profile->code, $draft->readiness_profile);
        $this->assertSame($profile->version, $draft->readiness_profile_version);
        $this->assertSame($profile->fingerprint, $draft->readiness_rule_set_fingerprint);

        $context = app(ReadinessContextBuilder::class)->build($product->company, $product);
        $result = app(PassportReadinessEvaluator::class)->evaluate($context);

        $this->assertSame($profile->fingerprint, $result->ruleSetFingerprint);
        $this->assertSame($expected['score'], $result->score);
        $this->assertSame($expected['earned_points'], $result->scoreBreakdown->earnedPoints);
        $this->assertSame($expected['applicable_points'], $result->scoreBreakdown->applicablePoints);
        $this->assertSame(
            $expected['failed_points'],
            $result->scoreBreakdown->applicablePoints - $result->scoreBreakdown->earnedPoints,
        );
        $this->assertSame($expected['failed_points_by_severity'], $result->scoreBreakdown->failedPointsBySeverity);
        $this->assertSame($expected['passed'], $result->counts->passed);
        $this->assertSame($expected['blockers'], $result->counts->blockers);
        $this->assertSame($expected['warnings'], $result->counts->warnings);
        $this->assertSame($expected['recommendations'], $result->counts->recommendations);
        $this->assertSame($expected['not_applicable'], $result->counts->notApplicable);
    }
}
