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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class DocumentContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private Product $product;

    private ProductDocument $document;

    private ProductDocumentVersion $docVersion1;

    private ProductDocumentVersion $docVersion2;

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

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $this->actor->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        Storage::fake('catalog_media');
        Storage::disk('catalog_media')->put('test/doc-contract.jpg', 'fake content');
        Storage::fake('product_documents');

        $category = new Category;
        $category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Doc Contract Category',
            'slug' => 'doc-contract-cat-'.fake()->unique()->slug(1),
            'slug_normalized' => 'doc-contract-cat-'.fake()->unique()->slug(1),
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
            'name' => 'Doc Contract Product '.fake()->unique()->word(),
            'slug' => 'doc-contract-product-'.fake()->unique()->slug(1),
            'slug_normalized' => 'doc-contract-product-'.fake()->unique()->slug(1),
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
            'sku' => 'SKU-DC-001',
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
            'original_filename' => 'doc-contract.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'test/doc-contract.jpg',
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
            'storage_key' => 'test/cert-v1.pdf',
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
            'storage_key' => 'test/cert-v2.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $this->document->forceFill(['current_version_id' => $this->docVersion2->getKey()])->save();
    }

    private function fillCoreSectionsAndSyncDocument(bool $includeVersionUuid = false): void
    {
        $this->passport = app(CreateProductPassportDraftAction::class)->handle(
            $this->actor,
            $this->company,
            $this->product,
        );

        $this->revision = $this->passport->currentDraftVersion->draft_revision;

        $this->fillSection(DppSectionKey::Identity, [
            'public_name' => 'Doc Contract Product Name',
            'public_description' => 'Document contract test description.',
        ]);

        $this->fillSection(DppSectionKey::ManufacturerAndOperator, [
            'manufacturer_display_name' => 'Test Manufacturer Inc.',
            'responsible_operator_display_name' => 'Test Operator',
            'contact_notes' => 'Contact notes.',
        ]);

        $this->injectManufacturerContact();

        $this->fillSection(DppSectionKey::Safety, [
            'warnings' => ['Contract safety warning'],
            'storage_instructions' => 'Store according to contract.',
        ]);

        $this->fillSection(DppSectionKey::RecyclingAndDisposal, [
            'recycling_instructions' => 'Contract recycling instructions.',
        ]);

        $refs = [
            [
                'document_uuid' => $this->document->uuid,
                'role' => 'certificate',
            ],
        ];

        if ($includeVersionUuid) {
            $refs[0]['document_version_uuid'] = $this->docVersion2->uuid;
        }

        $this->syncDocumentReferences($refs);
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
        $payload['data']['manufacturer_and_operator']['manufacturer_email'] = 'contact@doc-contract.example';

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

    private function freshPassport(): ProductPassport
    {
        return ProductPassport::query()
            ->forCompany($this->company)
            ->where('product_id', $this->product->getKey())
            ->first()
            ->fresh(['currentDraftVersion', 'currentPublishedVersion']);
    }

    public function test_draft_stores_only_document_uuid(): void
    {
        $this->fillCoreSectionsAndSyncDocument(includeVersionUuid: false);

        $passport = $this->freshPassport();
        $draftPayload = $passport->currentDraftVersion->payload;

        $this->assertArrayHasKey('document_references', $draftPayload);
        $this->assertNotEmpty($draftPayload['document_references']);

        $ref = $draftPayload['document_references'][0];

        $this->assertSame($this->document->uuid, $ref['document_uuid']);
        $this->assertTrue(
            empty($ref['document_version_uuid']),
            'Draft should not pin document_version_uuid.',
        );
    }

    public function test_published_version_pins_current_document_version(): void
    {
        $this->fillCoreSectionsAndSyncDocument(includeVersionUuid: false);

        $passport = $this->freshPassport();

        $result = $this->publish($passport, $this->revision);

        $publishedPayload = $result->publishedVersion->payload;
        $this->assertArrayHasKey('document_references', $publishedPayload);
        $this->assertNotEmpty($publishedPayload['document_references']);

        $ref = $publishedPayload['document_references'][0];

        $this->assertSame($this->document->uuid, $ref['document_uuid']);
        $this->assertNotEmpty($ref['document_version_uuid']);
        $this->assertSame(
            $this->docVersion2->uuid,
            $ref['document_version_uuid'],
            'Published version must pin current document version at publication time.',
        );
    }

    public function test_new_document_version_does_not_change_draft_reference(): void
    {
        $this->fillCoreSectionsAndSyncDocument(includeVersionUuid: false);

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
            'storage_key' => 'test/cert-v3.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $this->document->forceFill(['current_version_id' => $docVersion3->getKey()])->save();

        $passport = $this->freshPassport();
        $draftPayload = $passport->currentDraftVersion->payload;

        $ref = $draftPayload['document_references'][0];

        $this->assertSame($this->document->uuid, $ref['document_uuid']);
        $this->assertTrue(
            empty($ref['document_version_uuid']),
            'Draft must remain logical reference even after new document version added.',
        );
    }

    public function test_publish_pins_version_at_publication_time(): void
    {
        $this->fillCoreSectionsAndSyncDocument(includeVersionUuid: false);

        $passport = $this->freshPassport();

        $result = $this->publish($passport, $this->revision);

        $publishedPayload = $result->publishedVersion->payload;
        $this->assertSame(
            $this->docVersion2->uuid,
            $publishedPayload['document_references'][0]['document_version_uuid'],
            'Version 1 must be pinned to document Version 2.',
        );

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
            'storage_key' => 'test/cert-v3.pdf',
            'created_by_user_id' => $this->actor->getKey(),
        ])->save();

        $this->document->forceFill(['current_version_id' => $docVersion3->getKey()])->save();

        $publishedV1 = ProductPassportVersion::query()->find($result->publishedVersion->getKey());

        $pinnedRef = $publishedV1->payload['document_references'][0];
        $this->assertSame(
            $this->docVersion2->uuid,
            $pinnedRef['document_version_uuid'],
            'Published version must remain pinned to document Version 2 even after Version 3 is added.',
        );
    }

    public function test_new_draft_after_publish_uses_logical_reference(): void
    {
        $this->fillCoreSectionsAndSyncDocument(includeVersionUuid: false);

        $passport = $this->freshPassport();

        $this->publish($passport, $this->revision);

        $fresh = $this->freshPassport();
        $newDraftPayload = $fresh->currentDraftVersion->payload;

        $this->assertArrayHasKey('document_references', $newDraftPayload);
        $ref = $newDraftPayload['document_references'][0];

        $this->assertSame($this->document->uuid, $ref['document_uuid']);
        $this->assertTrue(
            empty($ref['document_version_uuid']),
            'New draft after publish must use logical reference only, no version pin.',
        );
    }
}
