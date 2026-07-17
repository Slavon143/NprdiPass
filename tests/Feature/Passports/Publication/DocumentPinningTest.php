<?php

namespace Tests\Feature\Passports\Publication;

use App\Actions\Passports\CreateProductPassportDraftAction;
use App\Actions\Passports\PublishProductPassport;
use App\Actions\Passports\SyncProductPassportDocumentsAction;
use App\Actions\Passports\UpdateProductPassportSectionAction;
use App\Data\Passports\PublicationResult;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\DppSectionKey;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
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
use Tests\TestCase;

class DocumentPinningTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private ProductPassport $passport;

    private ProductDocument $document;

    private ProductDocumentVersion $docVersion1;

    private ProductDocumentVersion $docVersion2;

    private ProductDocumentVersion $pinnedVersion;

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
        Storage::disk('catalog_media')->put('test/test.jpg', 'fake content');
        Storage::fake('product_documents');
        Storage::disk('product_documents')->put('test/doc-cert-v1.pdf', 'fake pdf v1 content');
        Storage::disk('product_documents')->put('test/doc-cert-v2.pdf', 'fake pdf v2 content');
        Storage::disk('product_documents')->put('test/doc-cert-v3.pdf', 'fake pdf v3 content');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Doc Pinning Category',
            'slug' => 'doc-pinning-category-'.fake()->unique()->slug(1),
            'slug_normalized' => 'doc-pinning-category-'.fake()->unique()->slug(1),
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
            'name' => 'Doc Pinning Product '.fake()->unique()->word(),
            'slug' => 'doc-pinning-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'doc-pinning-product-'.fake()->unique()->slug(1),
            'brand' => 'Test Brand',
            'manufacturer' => 'Test Manufacturer',
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
            'sku' => 'SKU-DOC-001',
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
            'original_filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/test.jpg',
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

        $this->document = ProductDocument::query()->forceCreate([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'product_id' => $this->product->getKey(),
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $this->actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->docVersion1 = new ProductDocumentVersion;
        $this->docVersion1->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'document_id' => $this->document->getKey(),
            'version_number' => 1,
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'Certificate v1',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'issuer_name' => 'Test Issuer',
            'issue_date' => now()->subMonth(),
            'original_filename' => 'cert-v1.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('1', 64),
            'storage_key' => 'test/doc-cert-v1.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $this->docVersion2 = new ProductDocumentVersion;
        $this->docVersion2->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'document_id' => $this->document->getKey(),
            'version_number' => 2,
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'Certificate v2',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'issuer_name' => 'Test Issuer',
            'issue_date' => now()->subMonth(),
            'original_filename' => 'cert-v2.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 2048,
            'checksum_sha256' => str_repeat('2', 64),
            'storage_key' => 'test/doc-cert-v2.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $this->document->forceFill(['current_version_id' => $this->docVersion2->getKey()])->save();

        $this->pinnedVersion = $this->docVersion2;
    }

    private function fillCoreSections(): void
    {
        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $this->passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Doc Pinning Product Name',
            'public_description' => 'Document pinning test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Test Manufacturer Inc.',
            'responsible_operator_display_name' => 'Test Operator',
            'contact_notes' => 'Contact notes.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Warning about pinning'],
            'storage_instructions' => 'Store pinned documents safely.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Recycle documents properly.',
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

    private function syncDocumentReferences(array $refs): void
    {
        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first();

        $result = app(SyncProductPassportDocumentsAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
            $passport,
            $refs,
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
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@doc-pinning.example';

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

    public function test_published_version_pins_specific_document_version(): void
    {
        $this->fillCoreSections();

        $this->syncDocumentReferences([
            [
                'document_uuid' => $this->document->uuid,
                'document_version_uuid' => $this->docVersion2->uuid,
                'role' => 'certificate',
            ],
        ]);

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $result = $this->publish($passport, $this->revision);

        $publishedPayload = $result->publishedVersion->payload;
        $this->assertArrayHasKey('document_references', $publishedPayload);

        $refs = $publishedPayload['document_references'];
        $this->assertCount(1, $refs);
        $this->assertArrayHasKey('document_version_uuid', $refs[0]);
        $this->assertSame($this->docVersion2->uuid, $refs[0]['document_version_uuid']);
    }

    public function test_new_document_version_does_not_affect_published_passport(): void
    {
        $this->fillCoreSections();

        $this->syncDocumentReferences([
            [
                'document_uuid' => $this->document->uuid,
                'document_version_uuid' => $this->docVersion2->uuid,
                'role' => 'certificate',
            ],
        ]);

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $result = $this->publish($passport, $this->revision);

        $originalPayload = $result->publishedVersion->payload;

        $docVersion3 = new ProductDocumentVersion;
        $docVersion3->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'document_id' => $this->document->getKey(),
            'version_number' => 3,
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'Certificate v3',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'issuer_name' => 'Test Issuer',
            'issue_date' => now()->subMonth(),
            'original_filename' => 'cert-v3.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 3072,
            'checksum_sha256' => str_repeat('3', 64),
            'storage_key' => 'test/doc-cert-v3.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $this->document->forceFill(['current_version_id' => $docVersion3->getKey()])->save();

        $publishedV1 = ProductPassportVersion::query()->find($result->publishedVersion->getKey());

        $this->assertEquals(
            $originalPayload['document_references'],
            $publishedV1->payload['document_references'],
            'Published document references must not change when new document version is added.',
        );
    }

    public function test_published_manifest_includes_document_checksum(): void
    {
        $this->fillCoreSections();

        $this->syncDocumentReferences([
            [
                'document_uuid' => $this->document->uuid,
                'document_version_uuid' => $this->docVersion2->uuid,
                'role' => 'certificate',
            ],
        ]);

        $passport = ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion']);

        $result = $this->publish($passport, $this->revision);

        $payload = $result->publishedVersion->payload;
        $this->assertArrayHasKey('document_references', $payload);

        $refs = $payload['document_references'];
        $this->assertCount(1, $refs);
        $this->assertArrayHasKey('document_version_uuid', $refs[0]);
        $this->assertSame($this->docVersion2->uuid, $refs[0]['document_version_uuid']);
        $this->assertSame($this->document->uuid, $refs[0]['document_uuid']);
    }
}
