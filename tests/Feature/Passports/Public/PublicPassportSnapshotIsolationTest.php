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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPassportSnapshotIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private Category $category;

    private ProductVariant $defaultVariant;

    private ProductMedia $primaryMedia;

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

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/product.jpg', 'fake-image-content');

        $this->category = new Category;
        $this->category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Snapshot Iso Category',
            'slug' => 'snapshot-iso-category-'.fake()->unique()->slug(1),
            'slug_normalized' => 'snapshot-iso-category-'.fake()->unique()->slug(1),
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
            'name' => 'Snapshot Iso Product '.fake()->unique()->word(),
            'slug' => 'snapshot-iso-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'snapshot-iso-product-'.fake()->unique()->slug(1),
            'brand' => 'Snapshot Iso Brand',
            'manufacturer' => 'Snapshot Iso Manufacturer',
            'description' => 'Snapshot Iso description.',
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
            'name' => 'Default Iso Variant',
            'sku' => 'SKU-ISO-001',
            'status' => ProductVariantStatus::Active,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->primaryMedia = new ProductMedia;
        $this->primaryMedia->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'original_filename' => 'product.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/product.jpg',
            'checksum_sha256' => str_repeat('a', 64),
            'sort_order' => 0,
            'uploaded_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product->forceFill([
            'default_variant_id' => $this->defaultVariant->getKey(),
            'primary_media_id' => $this->primaryMedia->getKey(),
        ])->save();

        $this->product->categories()->attach($this->category->getKey(), [
            'company_id' => $this->company->getKey(),
            'created_at' => now(),
        ]);

        $this->product->refresh();

        $this->createAndPublishV1();
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
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@snapshot-iso.example';

        $normalized = app(DppPayloadNormalizer::class)->normalize($payload);

        $newRevision = $draft->draft_revision + 1;
        $draft->setAttribute('payload', $normalized);
        $draft->setAttribute('draft_revision', $newRevision);
        $draft->setAttribute('updated_by', $this->actor->getKey());
        $draft->save();

        $this->revision = $newRevision;
    }

    private function createAndPublishV1(): void
    {
        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $this->passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Version 1 Name',
            'public_description' => 'V1 description.',
        ]);

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['V1 Warning'],
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'V1 recycling.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'V1 Manufacturer',
            'responsible_operator_display_name' => 'V1 Operator',
        ]);

        $this->injectManufacturerContact();

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

        $this->passport = $passport->fresh(['currentDraftVersion', 'currentPublishedVersion']);
        $this->revision = $this->passport->currentDraftVersion->draft_revision;
    }

    public function test_public_html_remains_based_on_version_1_after_live_product_changes(): void
    {
        $v1Html = $this->get("/p/{$this->passport->public_id}")->getContent();

        $this->assertStringContainsString('Version 1 Name', $v1Html);
        $this->assertStringContainsString('V1 description.', $v1Html);
        $this->assertStringContainsString('V1 Warning', $v1Html);
        $this->assertStringContainsString('V1 recycling.', $v1Html);

        $this->product->forceFill(['name' => 'Changed Live Product Name'])->save();
        $this->product->refresh();

        $this->defaultVariant->forceFill(['gtin' => '12345678901234'])->save();
        $this->defaultVariant->refresh();

        $draft = $this->passport->fresh(['currentDraftVersion'])->currentDraftVersion;
        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $this->passport,
            DppSectionKey::Identity->value,
            ['public_name' => 'Draft Changed Name'],
            $draft->draft_revision,
        );

        $newMedia = new ProductMedia;
        $newMedia->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'original_filename' => 'changed-image.jpg',
            'mime_type' => 'image/png',
            'size_bytes' => 2048,
            'storage_path' => 'test/changed-image.jpg',
            'checksum_sha256' => str_repeat('b', 64),
            'sort_order' => 1,
            'uploaded_by' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        $this->product->forceFill(['primary_media_id' => $newMedia->getKey()])->save();
        $this->product->refresh();

        $changedHtml = $this->get("/p/{$this->passport->public_id}")->getContent();

        $this->assertStringContainsString('Version 1 Name', $changedHtml);
        $this->assertStringContainsString('V1 description.', $changedHtml);
        $this->assertStringContainsString('V1 Warning', $changedHtml);
        $this->assertStringContainsString('V1 recycling.', $changedHtml);

        $this->assertStringNotContainsString('Changed Live Product Name', $changedHtml);
        $this->assertStringNotContainsString('12345678901234', $changedHtml);
        $this->assertStringNotContainsString('Draft Changed Name', $changedHtml);
    }

    public function test_stable_url_shows_version_2_after_republish(): void
    {
        $url = "/p/{$this->passport->public_id}";

        $v1Response = $this->get($url);
        $v1Response->assertSee('Version 1 Name');

        $passport = $this->passport->fresh(['currentDraftVersion']);
        $draftRev = $passport->currentDraftVersion->draft_revision;

        app(UpdateProductPassportSectionAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            DppSectionKey::Identity->value,
            [
                'public_name' => 'Version 2 Name',
                'public_description' => 'V2 description.',
            ],
            $draftRev,
        );

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $draftRev = $passport->currentDraftVersion->draft_revision;

        app(PublishProductPassport::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $draftRev,
            true,
        );

        $this->passport = $passport->fresh(['currentDraftVersion', 'currentPublishedVersion']);
        $this->revision = $this->passport->currentDraftVersion->draft_revision;

        $v2Response = $this->get($url);
        $v2Response->assertSee('Version 2 Name');
        $v2Response->assertDontSee('Version 1 Name');
    }
}
