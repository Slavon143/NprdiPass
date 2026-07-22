<?php

namespace Database\Seeders;

use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\Documents\ProductDocumentApprovalStatus;
use App\Enums\Documents\ProductDocumentReviewStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\DppSectionKey;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Passports\DppPayloadNormalizer;
use App\Services\Passports\Readiness\ReadinessProfileRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class R34DocumentsComplianceAcceptanceSeeder extends Seeder
{
    public const PRODUCT_SLUG = 'r3-4-documents-compliance-acceptance';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'acceptance'])) {
            return;
        }

        $this->call(LocalDevelopmentSeeder::class);

        DB::transaction(function (): void {
            $company = Company::query()->where('name', 'NordiPass Demo AB')->firstOrFail();
            $owner = User::query()->where('email', 'owner@nordipass.local')->firstOrFail();
            $category = $this->category($company);
            $product = $this->product($company, $owner, $category);
            [$variantA, $variantB] = $this->variants($company, $product);
            $this->media($company, $owner, $product);

            $documents = $this->documents($company, $owner, $product);

            $documents['certificate']->variants()->syncWithoutDetaching([
                $variantA->getKey() => [
                    'company_id' => $company->getKey(),
                    'public_inclusion' => true,
                    'required' => true,
                    'sort_order' => 10,
                    'metadata' => json_encode(['fixture' => 'public association'], JSON_THROW_ON_ERROR),
                ],
            ]);

            $documents['private']->variants()->syncWithoutDetaching([
                $variantB->getKey() => [
                    'company_id' => $company->getKey(),
                    'public_inclusion' => false,
                    'required' => false,
                    'sort_order' => 20,
                    'metadata' => json_encode(['fixture' => 'private association'], JSON_THROW_ON_ERROR),
                ],
            ]);

            $this->passport($company, $owner, $product, $documents);
        });
    }

    private function category(Company $company): Category
    {
        return Category::query()->firstOrCreate(
            [
                'company_id' => $company->getKey(),
                'slug_normalized' => 'r3-4-documents-compliance',
            ],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => 'R3.4 Documents Compliance',
                'slug' => 'r3-4-documents-compliance',
                'depth' => 0,
                'sort_order' => 0,
                'status' => CategoryStatus::Active,
            ],
        );
    }

    private function product(Company $company, User $owner, Category $category): Product
    {
        $product = Product::query()
            ->where('company_id', $company->getKey())
            ->where('slug_normalized', self::PRODUCT_SLUG)
            ->first() ?? new Product;

        $product->forceFill([
            'uuid' => $product->exists ? $product->uuid : '00000000-0000-4000-8000-000000003401',
            'company_id' => $company->getKey(),
            'name' => 'R3.4 Documents Compliance Acceptance',
            'slug' => self::PRODUCT_SLUG,
            'slug_normalized' => self::PRODUCT_SLUG,
            'short_description' => 'Deterministic R3.4 document workflow fixture.',
            'description' => 'Acceptance fixture for document versioning, review, approval, expiry and publication.',
            'brand' => 'NordiPass',
            'manufacturer' => 'NordiPass Demo Manufacturing AB',
            'status' => ProductStatus::Active,
            'primary_category_id' => $category->getKey(),
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ])->save();

        if (! $product->categories()->whereKey($category->getKey())->exists()) {
            $product->categories()->attach($category->getKey(), [
                'company_id' => $company->getKey(),
                'created_at' => now(),
            ]);
        }

        return $product;
    }

    /**
     * @return array{0: ProductVariant, 1: ProductVariant}
     */
    private function variants(Company $company, Product $product): array
    {
        $variantA = ProductVariant::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'product_id' => $product->getKey(), 'sku_normalized' => 'r34-a'],
            [
                'uuid' => '00000000-0000-4000-8000-000000003411',
                'name' => 'R3.4 Variant A',
                'sku' => 'R34-A',
                'status' => ProductVariantStatus::Active,
                'sort_order' => 10,
            ],
        );

        $variantB = ProductVariant::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'product_id' => $product->getKey(), 'sku_normalized' => 'r34-b'],
            [
                'uuid' => '00000000-0000-4000-8000-000000003412',
                'name' => 'R3.4 Variant B',
                'sku' => 'R34-B',
                'status' => ProductVariantStatus::Active,
                'sort_order' => 20,
            ],
        );

        $product->forceFill(['default_variant_id' => $variantA->getKey()])->save();

        return [$variantA, $variantB];
    }

    private function media(Company $company, User $owner, Product $product): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lG7P8QAAAABJRU5ErkJggg==', true);
        if ($png === false) {
            throw new \RuntimeException('R3.4 media fixture could not be decoded.');
        }

        $storagePath = "companies/{$company->uuid}/products/{$product->uuid}/media/r3-4-primary.png";
        Storage::disk('catalog_media')->put($storagePath, $png);

        $media = ProductMedia::query()->firstOrNew([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'storage_path' => $storagePath,
        ]);

        $media->forceFill([
            'uuid' => $media->exists ? $media->uuid : '00000000-0000-4000-8000-000000003421',
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'original_filename' => 'r3-4-primary.png',
            'storage_path' => $storagePath,
            'mime_type' => 'image/png',
            'size_bytes' => strlen($png),
            'width' => 1,
            'height' => 1,
            'checksum_sha256' => hash('sha256', $png),
            'alt_text' => 'R3.4 acceptance product image',
            'caption' => 'R3.4 acceptance product image',
            'sort_order' => 0,
            'uploaded_by' => $owner->getKey(),
        ])->save();

        $product->forceFill(['primary_media_id' => $media->getKey()])->save();
    }

    /**
     * @return array<string, ProductDocument>
     */
    private function documents(Company $company, User $owner, Product $product): array
    {
        return [
            'general' => $this->document($company, $owner, $product, 'general', ProductDocumentType::GeneralDocument, ProductDocumentVisibility::PassportPublic, ProductDocumentReviewStatus::Approved, ProductDocumentApprovalStatus::Approved),
            'declaration' => $this->document($company, $owner, $product, 'declaration', ProductDocumentType::DeclarationOfConformity, ProductDocumentVisibility::PassportPublic, ProductDocumentReviewStatus::Approved, ProductDocumentApprovalStatus::Approved, ['issuer_name' => 'NordiPass Demo Manufacturing AB', 'declaration_identifier' => 'DOC-R34-001']),
            'certificate' => $this->document($company, $owner, $product, 'certificate', ProductDocumentType::Certificate, ProductDocumentVisibility::PassportPublic, ProductDocumentReviewStatus::Approved, ProductDocumentApprovalStatus::Approved, ['issuer_name' => 'Nordic Certification Body', 'certificate_number' => 'CERT-R34-001']),
            'test_report' => $this->document($company, $owner, $product, 'test-report', ProductDocumentType::TestReport, ProductDocumentVisibility::Internal, ProductDocumentReviewStatus::Rejected, ProductDocumentApprovalStatus::Rejected, ['issuer_name' => 'R3 Test Lab', 'rejection_reason' => 'Fixture rejection reason']),
            'environmental' => $this->document($company, $owner, $product, 'environmental', ProductDocumentType::EnvironmentalEvidence, ProductDocumentVisibility::PassportPublic, ProductDocumentReviewStatus::PendingReview, ProductDocumentApprovalStatus::Pending, ['evidence_type' => 'provided compliance evidence']),
            'private' => $this->document($company, $owner, $product, 'private', ProductDocumentType::ComplianceEvidence, ProductDocumentVisibility::Internal, ProductDocumentReviewStatus::Approved, ProductDocumentApprovalStatus::Approved, ['evidence_type' => 'internal audit']),
            'expired' => $this->document($company, $owner, $product, 'expired-certificate', ProductDocumentType::Certificate, ProductDocumentVisibility::PassportPublic, ProductDocumentReviewStatus::Approved, ProductDocumentApprovalStatus::Approved, ['issuer_name' => 'Expired Body', 'certificate_number' => 'CERT-R34-EXPIRED', 'issue_date' => '2025-01-01', 'valid_from' => '2025-01-01', 'valid_until' => '2026-01-31']),
            'expiring' => $this->document($company, $owner, $product, 'expiring-certificate', ProductDocumentType::Certificate, ProductDocumentVisibility::PassportPublic, ProductDocumentReviewStatus::Approved, ProductDocumentApprovalStatus::Approved, ['issuer_name' => 'Expiring Body', 'certificate_number' => 'CERT-R34-EXPIRING', 'valid_until' => '2026-08-10']),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function document(
        Company $company,
        User $owner,
        Product $product,
        string $key,
        ProductDocumentType $type,
        ProductDocumentVisibility $visibility,
        ProductDocumentReviewStatus $reviewStatus,
        ProductDocumentApprovalStatus $approvalStatus,
        array $overrides = [],
    ): ProductDocument {
        $document = ProductDocument::query()
            ->where('company_id', $company->getKey())
            ->where('product_id', $product->getKey())
            ->whereHas('currentVersion', fn ($query) => $query->where('title', $this->title($key)))
            ->first() ?? new ProductDocument;

        $document->forceFill([
            'uuid' => $document->exists ? $document->uuid : Str::uuid()->toString(),
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductDocumentStatus::Active,
            'created_by_user_id' => $owner->getKey(),
            'updated_by_user_id' => $owner->getKey(),
        ])->save();

        if ($document->currentVersion === null) {
            $version = new ProductDocumentVersion;
            $version->forceFill([
                'uuid' => Str::uuid()->toString(),
                'company_id' => $company->getKey(),
                'document_id' => $document->getKey(),
                'version_number' => 1,
                'document_type' => $type,
                'title' => $this->title($key),
                'description' => 'R3.4 deterministic fixture document.',
                'language' => 'en',
                'visibility' => $visibility,
                'metadata' => ['fixture_key' => $key],
                'review_status' => $reviewStatus,
                'approval_status' => $approvalStatus,
                'issuer_name' => $overrides['issuer_name'] ?? null,
                'certificate_number' => $overrides['certificate_number'] ?? null,
                'declaration_identifier' => $overrides['declaration_identifier'] ?? null,
                'evidence_type' => $overrides['evidence_type'] ?? null,
                'issue_date' => $overrides['issue_date'] ?? '2026-06-01',
                'valid_from' => $overrides['valid_from'] ?? '2026-06-01',
                'valid_until' => $overrides['valid_until'] ?? '2027-06-01',
                'expires_at' => $overrides['valid_until'] ?? '2027-06-01',
                'original_filename' => "{$key}.pdf",
                'safe_display_filename' => "{$key}.pdf",
                'mime_type' => 'application/pdf',
                'file_extension' => 'pdf',
                'size_bytes' => strlen($this->pdf($key)),
                'checksum_sha256' => hash('sha256', $this->pdf($key)),
                'storage_key' => $this->storageKey($company, $product, $document, $key),
                'file_available' => true,
                'submitted_at' => $reviewStatus === ProductDocumentReviewStatus::PendingReview ? now() : null,
                'submitted_by_user_id' => $reviewStatus === ProductDocumentReviewStatus::PendingReview ? $owner->getKey() : null,
                'reviewed_at' => in_array($reviewStatus, [ProductDocumentReviewStatus::Approved, ProductDocumentReviewStatus::Rejected], true) ? now() : null,
                'reviewed_by_user_id' => in_array($reviewStatus, [ProductDocumentReviewStatus::Approved, ProductDocumentReviewStatus::Rejected], true) ? $owner->getKey() : null,
                'approved_at' => $approvalStatus === ProductDocumentApprovalStatus::Approved ? now() : null,
                'approved_by_user_id' => $approvalStatus === ProductDocumentApprovalStatus::Approved ? $owner->getKey() : null,
                'rejection_reason' => $overrides['rejection_reason'] ?? null,
                'created_by_user_id' => $owner->getKey(),
            ])->save();

            $document->forceFill(['current_version_id' => $version->getKey()])->save();
            Storage::disk('product_documents')->put($version->storage_key, $this->pdf($key));
        }

        return $document->refresh();
    }

    /**
     * @param  array<string, ProductDocument>  $documents
     */
    private function passport(Company $company, User $owner, Product $product, array $documents): void
    {
        $passport = ProductPassport::query()
            ->where('company_id', $company->getKey())
            ->where('product_id', $product->getKey())
            ->first() ?? new ProductPassport;

        $passport->forceFill([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductPassportStatus::Draft,
            'default_language' => 'en',
            'enabled_languages' => ['en'],
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ])->save();

        $profile = app(ReadinessProfileRepository::class)->active();
        $draft = $passport->currentDraftVersion ?? new ProductPassportVersion;
        $draft->forceFill([
            'uuid' => $draft->exists ? $draft->uuid : Str::uuid()->toString(),
            'company_id' => $company->getKey(),
            'passport_id' => $passport->getKey(),
            'status' => ProductPassportVersionStatus::Draft,
            'draft_revision' => $draft->exists ? $draft->draft_revision : 1,
            'schema_version' => '1',
            'payload' => $this->payload($documents),
            'readiness_profile' => $profile->code,
            'readiness_profile_version' => $profile->version,
            'readiness_rule_set_fingerprint' => $profile->fingerprint,
            'created_by' => $owner->getKey(),
            'updated_by' => $owner->getKey(),
        ])->save();

        $passport->forceFill(['current_draft_version_id' => $draft->getKey()])->save();
    }

    /**
     * @param  array<string, ProductDocument>  $documents
     * @return array<string, mixed>
     */
    private function payload(array $documents): array
    {
        return app(DppPayloadNormalizer::class)->normalize([
            'enabled_sections' => array_map(fn (DppSectionKey $section) => $section->value, DppSectionKey::cases()),
            'data' => [
                'identity' => ['public_name' => 'R3.4 Documents Compliance Acceptance'],
                'manufacturer_and_operator' => [
                    'manufacturer_display_name' => 'NordiPass Demo Manufacturing AB',
                    'manufacturer_country' => 'SE',
                    'manufacturer_email' => 'owner@nordipass.local',
                    'responsible_operator_display_name' => 'NordiPass Demo AB',
                ],
            ],
            'translations' => [
                'en' => [
                    'identity' => [
                        'public_name' => 'R3.4 Documents Compliance Acceptance',
                        'public_description' => 'Document compliance acceptance fixture.',
                    ],
                    'manufacturer_and_operator' => [
                        'responsible_operator_display_name' => 'NordiPass Demo AB',
                    ],
                    'safety' => [
                        'warnings' => ['Fixture warning'],
                        'storage_instructions' => 'Store normally.',
                        'emergency_instructions' => 'Disconnect power and contact support.',
                    ],
                    'recycling_and_disposal' => [
                        'recycling_instructions' => 'Recycle through standard channels.',
                        'disposal_instructions' => 'Dispose through approved electronics waste collection.',
                    ],
                ],
            ],
            'document_references' => [
                ['document_uuid' => $documents['declaration']->uuid, 'role' => ProductDocumentType::DeclarationOfConformity->value, 'display_order' => 10],
                ['document_uuid' => $documents['certificate']->uuid, 'role' => ProductDocumentType::Certificate->value, 'display_order' => 20],
                ['document_uuid' => $documents['private']->uuid, 'role' => ProductDocumentType::ComplianceEvidence->value, 'display_order' => 30],
            ],
        ]);
    }

    private function title(string $key): string
    {
        return 'R3.4 '.str_replace('-', ' ', Str::title($key));
    }

    private function storageKey(Company $company, Product $product, ProductDocument $document, string $key): string
    {
        return "companies/{$company->uuid}/products/{$product->uuid}/documents/{$document->uuid}/versions/{$key}.pdf";
    }

    private function pdf(string $key): string
    {
        return "%PDF-1.4\n% R3.4 {$key}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n%%EOF";
    }
}
