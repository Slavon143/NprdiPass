<?php

namespace Tests\Feature\Passports\Publication;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\PassportValidationRun;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\Readiness\PassportReadinessRuleRegistry;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private ProductPassportVersion $publishedVersion;

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

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/immutability.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Immutability Category',
            'slug' => 'immutability-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'immutability-cat-'.fake()->unique()->slug(1),
            'depth' => 0,
            'sort_order' => 0,
            'status' => CategoryStatus::Active,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product = new Product;
        $this->product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Immutability Product '.fake()->unique()->word(),
            'slug' => 'immutability-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'immutability-product-'.fake()->unique()->slug(1),
            'brand' => 'Immutability Brand',
            'manufacturer' => 'Immutability Manufacturer',
            'status' => ProductStatus::Active,
            'primary_category_id' => $category->getKey(),
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $variant = new ProductVariant;
        $variant->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'name' => 'Default Variant',
            'sku' => 'SKU-IM-001',
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $media = new ProductMedia;
        $media->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'original_filename' => 'immutability.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/immutability.jpg',
            'checksum_sha256' => str_repeat('a', 64),
            'sort_order' => 0,
            'uploaded_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product->forceFill([
            'default_variant_id' => $variant->getKey(),
            'primary_media_id' => $media->getKey(),
        ])->save();

        $this->product->categories()->attach($category->getKey(), [
            'company_id' => $this->company->getKey(),
            'created_at' => now(),
        ]);

        $this->product->refresh();

        $passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Immutability Product Name',
            'public_description' => 'Immutability test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Immutability Mfg Inc.',
            'responsible_operator_display_name' => 'Immutability Operator',
            'contact_notes' => 'Immutability contact.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Immutability warning'],
            'storage_instructions' => 'Immutability storage.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Immutability recycling.',
        ]);

        $this->fillSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Plastic', 'percentage' => 100.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
            ],
        ]);

        $this->fillSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 10.0,
        ]);

        $this->fillSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Usage.',
        ]);

        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => 'Repair.',
        ]);

        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => 'Warranty.',
        ]);

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $result = app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->publishedVersion = $result->publishedVersion;
    }

    private function fillSection(DppSectionKey $section, array $payload): void
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
        );

        $this->revision = $result->currentDraftVersion->draft_revision;
    }

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@immutability.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    public function test_cannot_update_snapshot_via_eloquent(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->publishedVersion->update([
            'payload' => ['tampered' => true],
        ]);
    }

    public function test_cannot_update_snapshot_via_query_builder(): void
    {
        $this->expectException(QueryException::class);

        DB::table('product_passport_versions')
            ->where('id', $this->publishedVersion->getKey())
            ->update([
                'payload' => json_encode(['tampered' => true]),
            ]);
    }

    public function test_cannot_update_checksum_via_eloquent(): void
    {
        $originalChecksum = $this->publishedVersion->content_checksum;

        $this->publishedVersion->update([
            'content_checksum' => hash('sha256', 'tampered'),
        ]);

        $this->publishedVersion->refresh();
        $this->assertSame($originalChecksum, $this->publishedVersion->content_checksum);
    }

    public function test_cannot_update_version_number(): void
    {
        $originalVersion = $this->publishedVersion->version_number;

        $this->publishedVersion->update([
            'version_number' => 999,
        ]);

        $this->publishedVersion->refresh();
        $this->assertSame($originalVersion, $this->publishedVersion->version_number);
    }

    public function test_can_update_lifecycle_fields(): void
    {
        $version = ProductPassportVersion::query()->find($this->publishedVersion->getKey());

        $version->setAttribute('status', ProductPassportVersionStatus::Superseded);
        $version->setAttribute('superseded_at', now());
        $version->save();

        $version->refresh();

        $this->assertSame(
            ProductPassportVersionStatus::Superseded,
            $version->status,
        );

        $this->assertNotNull($version->superseded_at);
    }

    public function test_published_version_carries_reproducible_readiness_evidence(): void
    {
        $version = $this->publishedVersion->fresh(['validationRun.results']);

        $this->assertNotNull($version->validationRun);
        $this->assertSame($version->validation_run_id, $version->validationRun->getKey());
        $this->assertSame($version->readiness_evidence['validation_run_uuid'], $version->validationRun->uuid);
        $this->assertSame($version->draft_revision, $version->validationRun->draft_revision);
        $this->assertSame($version->readiness_evidence['score'], $version->validationRun->score);
        $this->assertSame('weighted_ratio', $version->readiness_evidence['score_algorithm']);
        $this->assertSame($version->readiness_evidence['rule_set_fingerprint'], $version->validationRun->rule_set_fingerprint);
        $this->assertSame($version->readiness_rule_set_fingerprint, $version->validationRun->rule_set_fingerprint);
        $this->assertSame(64, strlen($version->validationRun->source_checksum));
        $this->assertSame(64, strlen($version->validationRun->rule_set_fingerprint));
        $this->assertCount(count(app(PassportReadinessRuleRegistry::class)->all()), $version->validationRun->results);
    }

    public function test_validation_run_and_results_are_database_immutable(): void
    {
        $run = PassportValidationRun::query()->findOrFail($this->publishedVersion->validation_run_id);

        try {
            DB::table('passport_validation_runs')->where('id', $run->getKey())->update(['score' => 0]);
            $this->fail('Validation run update should have been rejected.');
        } catch (QueryException) {
            $this->assertSame($run->score, $run->fresh()->score);
        }

        $this->expectException(QueryException::class);
        DB::table('passport_validation_results')
            ->where('validation_run_id', $run->getKey())
            ->limit(1)
            ->update(['status' => 'failed']);
    }

    public function test_published_readiness_evidence_cannot_be_changed_during_supersede(): void
    {
        $this->expectException(QueryException::class);

        DB::table('product_passport_versions')
            ->where('id', $this->publishedVersion->getKey())
            ->update([
                'status' => ProductPassportVersionStatus::Superseded->value,
                'superseded_at' => now(),
                'readiness_evidence' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR),
            ]);
    }
}
