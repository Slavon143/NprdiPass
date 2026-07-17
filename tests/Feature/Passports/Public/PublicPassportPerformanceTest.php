<?php

namespace Tests\Feature\Passports\Public;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPassportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY_BUDGET = 30;

    private Company $company;

    private User $actor;

    private Product $product;

    private Category $category;

    private ProductVariant $defaultVariant;

    private ProductPassport $passport;

    private int $revision = 1;

    private string $publicId;

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

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/perf.jpg', 'fake-content');

        $this->category = new Category;
        $this->category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Perf Category',
            'slug' => 'perf-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'perf-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Perf Product '.fake()->unique()->word(),
            'slug' => 'perf-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'perf-product-'.fake()->unique()->slug(1),
            'brand' => 'Perf Brand',
            'manufacturer' => 'Perf Manufacturer',
            'description' => 'Performance test product description.',
            'status' => ProductStatus::Active,
            'primary_category_id' => $this->category->getKey(),
            'created_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->defaultVariant = new ProductVariant;
        $this->defaultVariant->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'name' => 'Default Variant',
            'sku' => 'SKU-PERF-001',
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        for ($i = 2; $i <= 5; $i++) {
            $variant = new ProductVariant;
            $variant->forceFill([
                'uuid' => (string) str()->uuid(),
                'company_id' => $this->company->getKey(),
                'product_id' => $this->product->getKey(),
                'name' => "Variant {$i}",
                'sku' => "SKU-PERF-00{$i}",
                'status' => ProductVariantStatus::Active,
                'sort_order' => $i - 1,
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();
        }

        $primaryMedia = null;
        for ($i = 0; $i < 10; $i++) {
            $media = new ProductMedia;
            $media->forceFill([
                'uuid' => (string) str()->uuid(),
                'company_id' => $this->company->getKey(),
                'product_id' => $this->product->getKey(),
                'original_filename' => "perf-media-{$i}.jpg",
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
                'storage_path' => 'test/perf.jpg',
                'checksum_sha256' => str_repeat((string) $i, 64),
                'sort_order' => $i,
                'uploaded_by' => $this->actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            if ($i === 0) {
                $primaryMedia = $media;
            }
        }

        $this->product->forceFill([
            'default_variant_id' => $this->defaultVariant->getKey(),
            'primary_media_id' => $primaryMedia->getKey(),
        ])->save();

        $this->product->categories()->attach($this->category->getKey(), [
            'company_id' => $this->company->getKey(),
            'created_at' => now(),
        ]);

        $this->product->refresh();

        $this->createAndPublishPassport();
    }

    private function fillSection(DppSectionKey $section, array $payload): ProductPassport
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

        return $result;
    }

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@perf-mfg.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    private function createAndPublishPassport(): void
    {
        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $this->passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Performance Test Product',
            'public_description' => 'A product for performance testing.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Perf Manufacturer Inc.',
            'responsible_operator_display_name' => 'Perf Operator',
            'contact_notes' => 'Performance test contact notes.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::OriginAndTraceability, [
            'country_of_origin' => 'DE',
            'manufacturing_countries' => ['DE', 'PL'],
            'production_date' => '2026-01-15',
        ]);

        $this->fillSection(DppSectionKey::MaterialsAndComposition, [
            'materials' => [
                ['name' => 'Plastic', 'percentage' => 50.0, 'recycled_content_percentage' => 20.0, 'hazardous' => false],
                ['name' => 'Metal', 'percentage' => 40.0, 'recycled_content_percentage' => 30.0, 'hazardous' => false],
                ['name' => 'Electronics', 'percentage' => 10.0, 'recycled_content_percentage' => 5.0, 'hazardous' => true],
            ],
        ]);

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Warning: Keep away from water.'],
            'storage_instructions' => 'Store in a dry place at room temperature.',
            'age_restrictions' => '18+',
        ]);

        $this->fillSection(DppSectionKey::UsageAndCare, [
            'usage_instructions' => 'Follow the user manual for proper use.',
            'care_instructions' => 'Clean with a dry cloth only.',
        ]);

        $this->fillSection(DppSectionKey::RepairAndSpareParts, [
            'repair_instructions' => 'Contact authorized service center for repairs.',
            'spare_parts_notes' => 'Available for 5 years after purchase.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Disassemble and sort by material type.',
            'disposal_instructions' => 'Do not dispose of in household waste.',
        ]);

        $this->fillSection(DppSectionKey::EnvironmentalInformation, [
            'carbon_footprint_kg_co2e' => 15.0,
            'recycled_content_percentage' => 30.0,
        ]);

        $this->fillSection(DppSectionKey::CertificationsAndDocuments, [
            'certification_notes' => 'ISO 9001 certified.',
            'compliance_summary' => 'CE Mark compliant.',
        ]);

        $this->fillSection(DppSectionKey::SupportAndContact, [
            'warranty_summary' => '2-year limited warranty.',
            'support_email' => 'support@perf-mfg.example',
            'support_phone' => '+49-123-456789',
            'support_url' => 'https://support.perf-mfg.example',
        ]);

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $this->revision = $passport->currentDraftVersion->draft_revision;

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        $this->passport = $passport->fresh(['currentPublishedVersion']);
        $this->publicId = $this->passport->public_id;
    }

    public function test_public_page_query_count_is_bounded(): void
    {
        auth()->guard('web')->logout();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('public.passports.show', ['publicId' => $this->publicId]))
            ->assertOk();

        $queries = DB::getQueryLog();

        $queryCount = count($queries);

        $this->assertLessThanOrEqual(
            self::QUERY_BUDGET,
            $queryCount,
            sprintf(
                'Public passport page executed %d queries, exceeding budget of %d.',
                $queryCount,
                self::QUERY_BUDGET,
            ),
        );

        $sql = implode(' ', array_map(fn (array $q) => $q['query'], $queries));

        $this->assertStringNotContainsString(
            '"products"',
            $sql,
            'Public passport resolver should not query the live products table.',
        );

        $this->assertStringNotContainsString(
            '"product_document_versions"',
            $sql,
            'Public passport resolver should not query the live product_document_versions table.',
        );

        $this->assertStringNotContainsString(
            '"product_document_links"',
            $sql,
            'Public passport resolver should not query the product_document_links table.',
        );

        $this->assertStringNotContainsString(
            '"product_documents"',
            $sql,
            'Public passport resolver should not query the product_documents table.',
        );

        $this->assertStringNotContainsString(
            'readiness',
            $sql,
            'Public passport resolver should not execute any readiness-related queries.',
        );

        $this->assertStringNotContainsString(
            'PassportReadinessEvaluator',
            $sql,
            'Public passport resolver should not invoke readiness evaluator.',
        );

        $this->assertStringNotContainsString(
            'ReadinessContextBuilder',
            $sql,
            'Public passport resolver should not invoke readiness context builder.',
        );
    }

    public function test_public_page_does_not_recalculate_checksum(): void
    {
        auth()->guard('web')->logout();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('public.passports.show', ['publicId' => $this->publicId]))
            ->assertOk();

        $queries = DB::getQueryLog();
        $sql = implode(' ', array_map(fn (array $q) => strtolower($q['query']), $queries));

        $this->assertStringNotContainsString(
            'content_checksum',
            strtolower($sql),
            'Public passport page should never recalculate content_checksum.',
        );

        $this->assertStringNotContainsString(
            'json_checksum',
            strtolower($sql),
            'Public passport page should never recalculate json_checksum.',
        );
    }

    public function test_public_page_uses_snapshot_not_live_product_queries(): void
    {
        auth()->guard('web')->logout();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('public.passports.show', ['publicId' => $this->publicId]))
            ->assertOk();

        $queries = DB::getQueryLog();
        $sql = implode(' ', array_map(fn (array $q) => $q['query'], $queries));

        $this->assertStringContainsString(
            'product_passports',
            $sql,
            'Public passport resolver must look up the passport record.',
        );

        $this->assertStringContainsString(
            'product_passport_versions',
            $sql,
            'Public passport resolver must look up the published version record.',
        );
    }
}
