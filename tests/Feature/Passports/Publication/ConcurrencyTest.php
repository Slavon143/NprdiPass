<?php

namespace Tests\Feature\Passports\Publication;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\UnpublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Data\Passports\PublicationResult;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

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

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/concurrency.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Concurrency Category',
            'slug' => 'concurrency-category-'.fake()->unique()->slug(1),
            'slug_normalized' => 'concurrency-category-'.fake()->unique()->slug(1),
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
            'name' => 'Concurrency Product '.fake()->unique()->word(),
            'slug' => 'concurrency-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'concurrency-product-'.fake()->unique()->slug(1),
            'brand' => 'Concurrency Brand',
            'manufacturer' => 'Concurrency Manufacturer',
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
            'sku' => 'SKU-CC-001',
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
            'original_filename' => 'concurrency.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/concurrency.jpg',
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
    }

    private function fillAllSections(): void
    {
        $passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Concurrency Product',
            'public_description' => 'Concurrency test.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Concurrency Mfg',
            'responsible_operator_display_name' => 'Concurrency Op',
            'contact_notes' => 'Contact.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Concurrency warning'],
            'storage_instructions' => 'Concurrency storage.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Concurrency recycling.',
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

    private function freshPassport(): ProductPassport
    {
        return ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion', 'currentPublishedVersion']);
    }

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@concurrency-mfg.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    private function publish(ProductPassport $passport, int $revision): PublicationResult
    {
        return app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $revision,
            true,
        );
    }

    public function test_two_simultaneous_publishes_one_succeeds_one_conflicts(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $first = $this->publish($passport, $revision);

        $this->assertSame(ProductPassportStatus::Published, $first->passport->status);

        $this->expectException(ConflictHttpException::class);

        $this->publish($passport, $revision);
    }

    public function test_concurrent_publish_and_draft_edit(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();
        $revision = $this->revision;

        $this->publish($passport, $revision);

        $this->expectException(ConflictHttpException::class);

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::Identity->value,
            ['public_name' => 'Stale edit after publish'],
            $revision,
        );
    }

    public function test_version_number_monotonic(): void
    {
        $this->fillAllSections();

        $passport = $this->freshPassport();

        $v1 = $this->publish($passport, $this->revision);

        $this->assertSame(1, $v1->publishedVersion->version_number);

        $passport = $this->freshPassport();

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::Identity->value,
            ['public_name' => 'Version 2'],
            $passport->currentDraftVersion->draft_revision,
        );

        $passport = $this->freshPassport();

        $v2 = $this->publish($passport, $passport->currentDraftVersion->draft_revision);

        $this->assertSame(2, $v2->publishedVersion->version_number);

        $passport = $this->freshPassport();

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::Identity->value,
            ['public_name' => 'Version 3'],
            $passport->currentDraftVersion->draft_revision,
        );

        $passport = $this->freshPassport();

        $v3 = $this->publish($passport, $passport->currentDraftVersion->draft_revision);

        $this->assertSame(3, $v3->publishedVersion->version_number);

        $allVersions = ProductPassportVersion::query()
            ->where('passport_id', $passport->getKey())
            ->orderBy('version_number')
            ->get();

        $this->assertCount(3, $allVersions->filter(fn ($v) => $v->version_number !== null));
    }

    public function test_publish_versus_unpublish_race(): void
    {
        $this->fillAllSections();

        $passport = $this->freshPassport();

        $result = $this->publish($passport, $this->revision);

        $this->assertSame(ProductPassportStatus::Published, $result->passport->status);

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        $fresh = $this->freshPassport();
        $this->assertSame(ProductPassportStatus::Unpublished, $fresh->status);
        $this->assertNull($fresh->current_published_version_id);
    }
}
