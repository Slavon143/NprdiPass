<?php

namespace Tests\Feature\Passports\Publication;

use App\Actions\Passports\ArchiveProductPassport;
use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\RestoreProductPassport;
use App\Actions\Passports\UnpublishProductPassport;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Passports\DppSectionKey;
use App\Events\Passports\ProductPassportArchived;
use App\Events\Passports\ProductPassportPublished;
use App\Events\Passports\ProductPassportRestored;
use App\Events\Passports\ProductPassportUnpublished;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use App\Services\Passports\CanonicalJsonEncoder;
use App\Services\Passports\DppPayloadNormalizer;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class DomainEventsTest extends TestCase
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

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/domain-events.jpg', 'fake content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Domain Events Category',
            'slug' => 'domain-events-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'domain-events-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Domain Events Product '.fake()->unique()->word(),
            'slug' => 'domain-events-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'domain-events-product-'.fake()->unique()->slug(1),
            'brand' => 'Domain Events Brand',
            'manufacturer' => 'Domain Events Manufacturer',
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
            'sku' => 'SKU-DE-001',
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
            'original_filename' => 'domain-events.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/domain-events.jpg',
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
            'public_name' => 'Domain Events Product Name',
            'public_description' => 'Domain events test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Domain Events Mfg Inc.',
            'responsible_operator_display_name' => 'Domain Events Operator',
            'contact_notes' => 'Domain events contact.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Domain events warning'],
            'storage_instructions' => 'Domain events storage.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Domain events recycling.',
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

    private function injectManufacturerContact(): void
    {
        $draft = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->currentDraftVersion;

        $payload = $draft->payload;
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@domain-events.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    private function freshPassport(): ProductPassport
    {
        return ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion', 'currentPublishedVersion']);
    }

    public function test_publish_dispatches_event_after_commit(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();

        Event::fake([ProductPassportPublished::class]);

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        Event::assertDispatched(ProductPassportPublished::class);
    }

    public function test_unpublish_dispatches_event(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        Event::fake();

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        Event::assertDispatched(ProductPassportUnpublished::class);
    }

    public function test_archive_dispatches_event(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        Event::fake();

        app(ArchiveProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        Event::assertDispatched(ProductPassportArchived::class);
    }

    public function test_restore_dispatches_event(): void
    {
        $this->fillAllSections();
        $passport = $this->freshPassport();

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $this->revision,
            true,
        );

        app(UnpublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        app(ArchiveProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        Event::fake();

        app(RestoreProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->freshPassport(),
        );

        Event::assertDispatched(ProductPassportRestored::class);
    }

    public function test_failed_publication_dispatches_no_event(): void
    {
        $passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        Event::fake();

        try {
            app(PublishProductPassport::class)->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                1,
            );
        } catch (\Throwable) {
        }

        Event::assertNotDispatched(ProductPassportPublished::class);
    }

    public function test_rollback_dispatches_no_event(): void
    {
        $this->fillAllSections();

        $mockEncoder = $this->createMock(CanonicalJsonEncoder::class);
        $mockEncoder->method('hash')
            ->willThrowException(new \RuntimeException('Forced failure for rollback test'));

        $this->instance(CanonicalJsonEncoder::class, $mockEncoder);

        $passport = $this->freshPassport();

        Event::fake([ProductPassportPublished::class]);

        try {
            app(PublishProductPassport::class)->handle(
                $this->actor,
                $this->company,
                $this->product,
                $passport,
                $this->revision,
                true,
            );
        } catch (\RuntimeException) {
        }

        Event::assertNotDispatched(ProductPassportPublished::class);
    }
}
