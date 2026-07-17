<?php

namespace Tests\Feature\Passports\Localization;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\Localization\PassportLocaleRegistry;
use App\Services\Passports\Localization\PassportTranslationCompletenessEvaluator;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassportTranslationReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private ProductPassport $passport;

    private int $revision = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['status' => CompanyStatus::Active]);
        $this->actor = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->actor->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($this->actor);
        app(CurrentCompany::class)->set($this->company);

        $this->product = new Product;
        $this->product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Readiness Product',
            'slug' => 'readiness-'.fake()->unique()->slug(1),
            'slug_normalized' => 'readiness-'.fake()->unique()->slug(1),
            'status' => ProductStatus::Active,
            'created_by' => $this->actor->getKey(),
        ])->save();

        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $this->passport->currentDraftVersion->draft_revision;
    }

    private function completenessEvaluator(): PassportTranslationCompletenessEvaluator
    {
        return app(PassportTranslationCompletenessEvaluator::class);
    }

    private function saveSection(DppSectionKey $section, array $payload, string $locale): void
    {
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $result = app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $section->value,
            $payload,
            $this->revision,
            $locale,
        );

        $this->revision = $result->currentDraftVersion->draft_revision;
    }

    public function test_default_language_incomplete_returns_blocker(): void
    {
        $this->saveSection(DppSectionKey::Identity, ['public_name' => 'Partially Filled'], 'sv');

        $draft = $this->passport->fresh(['currentDraftVersion'])->currentDraftVersion;
        $results = $this->completenessEvaluator()->evaluate($draft->payload, ['sv'], 'sv');

        $this->assertSame('incomplete', $results['sv']->status);
        $this->assertTrue($results['sv']->hasRequiredMissing());
    }

    public function test_default_language_complete_passes(): void
    {
        $this->saveSection(DppSectionKey::Identity, ['public_name' => 'Full', 'public_description' => 'Desc.'], 'sv');
        $this->saveSection(DppSectionKey::Safety, ['warnings' => ['W'], 'storage_instructions' => 'S', 'emergency_instructions' => 'E'], 'sv');
        $this->saveSection(DppSectionKey::RecyclingAndDisposal, ['recycling_instructions' => 'R', 'disposal_instructions' => 'D'], 'sv');
        $this->saveSection(DppSectionKey::ManufacturerAndOperator, ['manufacturer_display_name' => 'M', 'responsible_operator_display_name' => 'O'], 'sv');

        $draft = $this->passport->fresh(['currentDraftVersion'])->currentDraftVersion;
        $results = $this->completenessEvaluator()->evaluate($draft->payload, ['sv'], 'sv');

        $this->assertSame('complete', $results['sv']->status);
    }

    public function test_additional_language_incomplete_is_warning(): void
    {
        $this->passport->setAttribute('enabled_languages', ['sv', 'en']);
        $this->passport->setAttribute('default_language', 'sv');
        $this->passport->save();

        $draft = $this->passport->fresh(['currentDraftVersion'])->currentDraftVersion;
        $results = $this->completenessEvaluator()->evaluate($draft->payload, ['sv', 'en'], 'sv');

        $this->assertSame('not_started', $results['en']->status);
        $this->assertSame(0, $results['en']->completion);
    }

    public function test_additional_language_complete_passes(): void
    {
        $this->passport->setAttribute('enabled_languages', ['sv', 'en']);
        $this->passport->setAttribute('default_language', 'sv');
        $this->passport->save();

        $this->saveSection(DppSectionKey::Identity, ['public_name' => 'EN Name', 'public_description' => 'EN Desc.'], 'en');
        $this->saveSection(DppSectionKey::Safety, ['warnings' => ['W'], 'storage_instructions' => 'S', 'emergency_instructions' => 'E'], 'en');
        $this->saveSection(DppSectionKey::RecyclingAndDisposal, ['recycling_instructions' => 'R', 'disposal_instructions' => 'D'], 'en');
        $this->saveSection(DppSectionKey::ManufacturerAndOperator, ['manufacturer_display_name' => 'M', 'responsible_operator_display_name' => 'O'], 'en');

        $draft = $this->passport->fresh(['currentDraftVersion'])->currentDraftVersion;
        $results = $this->completenessEvaluator()->evaluate($draft->payload, ['sv', 'en'], 'sv');

        $this->assertSame('complete', $results['en']->status);
    }

    public function test_unsupported_language_detected(): void
    {
        $registry = app(PassportLocaleRegistry::class);
        $this->assertFalse($registry->supports('de'));
        $this->assertFalse($registry->supports('fr'));
        $this->assertTrue($registry->supports('en'));
        $this->assertTrue($registry->supports('sv'));
    }
}
