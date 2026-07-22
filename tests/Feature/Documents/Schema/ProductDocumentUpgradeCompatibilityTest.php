<?php

namespace Tests\Feature\Documents\Schema;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\Passports\ProductPassportVersion;
use App\Models\User;
use App\Services\Catalog\Documents\ProductDocumentCurrentVersionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ProductDocumentUpgradeCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_r3_3_shaped_document_versions_remain_compatible_after_r3_4_schema_expansion(): void
    {
        [$company, $user, $product] = $this->context();

        $document = ProductDocument::query()->forceCreate([
            'uuid' => (string) str()->uuid(),
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyVersionUuid = (string) str()->uuid();
        $legacyChecksum = str_repeat('b', 64);

        DB::table('product_document_versions')->insert([
            'uuid' => $legacyVersionUuid,
            'company_id' => $company->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 1,
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'R3.3 Legacy Certificate',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'issuer_name' => 'Legacy Issuer',
            'issue_date' => now()->subMonth()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'original_filename' => 'legacy-certificate.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1200,
            'checksum_sha256' => $legacyChecksum,
            'storage_key' => 'legacy/r3-3-certificate.pdf',
            'created_by_user_id' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyVersion = ProductDocumentVersion::query()
            ->where('uuid', $legacyVersionUuid)
            ->firstOrFail();

        $document->forceFill(['current_version_id' => $legacyVersion->getKey()])->save();

        $this->assertSame('approved', $legacyVersion->getRawOriginal('review_status'));
        $this->assertSame('approved', $legacyVersion->getRawOriginal('approval_status'));
        $this->assertTrue((bool) $legacyVersion->getRawOriginal('file_available'));
        $this->assertNull($legacyVersion->metadata, 'R3.4 must not invent unknown certificate metadata for legacy rows.');
        $this->assertSame($legacyChecksum, $legacyVersion->checksum_sha256);

        $resolved = app(ProductDocumentCurrentVersionResolver::class)->resolve($document->refresh(), publicOnly: true);

        $this->assertNotNull($resolved);
        $this->assertSame($legacyVersionUuid, $resolved->uuid);
    }

    public function test_historical_published_document_snapshot_is_not_rewritten_after_source_document_changes(): void
    {
        [$company, $user, $product] = $this->context();

        $document = ProductDocument::factory()
            ->for($company)
            ->for($product)
            ->withInitialVersion([
                'uuid' => '00000000-0000-4000-8000-000000003451',
                'company_id' => $company->getKey(),
                'document_type' => ProductDocumentType::Certificate->value,
                'visibility' => ProductDocumentVisibility::PassportPublic->value,
                'title' => 'Historical Certificate v1',
                'checksum_sha256' => str_repeat('1', 64),
                'storage_key' => 'historical/certificate-v1.pdf',
            ])
            ->create([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'created_by_user_id' => $user->getKey(),
            ]);

        $version1 = $document->currentVersion;

        $passport = new ProductPassport;
        $passport->forceFill([
            'uuid' => (string) str()->uuid(),
            'public_id' => Uuid::uuid7()->toString(),
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductPassportStatus::Published,
            'default_language' => 'sv',
            'enabled_languages' => ['sv'],
            'first_published_at' => now(),
            'last_published_at' => now(),
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ])->save();

        $historicalPayload = [
            'document_references' => [[
                'document_uuid' => $document->uuid,
                'document_version_uuid' => $version1->uuid,
                'role' => ProductDocumentType::Certificate->value,
                'checksum_sha256' => $version1->checksum_sha256,
            ]],
        ];
        $historicalHash = hash('sha256', json_encode($historicalPayload, JSON_THROW_ON_ERROR));

        $publishedVersion = new ProductPassportVersion;
        $publishedVersion->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $company->getKey(),
            'passport_id' => $passport->getKey(),
            'status' => ProductPassportVersionStatus::Published,
            'version_number' => 1,
            'draft_revision' => 7,
            'schema_version' => '1',
            'payload' => $historicalPayload,
            'content_checksum' => $historicalHash,
            'published_at' => now(),
            'published_by' => $user->getKey(),
            'created_by' => $user->getKey(),
        ])->save();

        $passport->forceFill(['current_published_version_id' => $publishedVersion->getKey()])->save();

        $version2 = new ProductDocumentVersion;
        $version2->forceFill([
            'uuid' => '00000000-0000-4000-8000-000000003452',
            'company_id' => $company->getKey(),
            'document_id' => $document->getKey(),
            'version_number' => 2,
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'Historical Certificate v2',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'original_filename' => 'certificate-v2.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 2400,
            'checksum_sha256' => str_repeat('2', 64),
            'storage_key' => 'historical/certificate-v2.pdf',
            'created_by_user_id' => $user->getKey(),
        ])->save();

        $document->forceFill([
            'current_version_id' => $version2->getKey(),
            'status' => ProductDocumentStatus::Archived->value,
            'archived_at' => now(),
        ])->save();

        $afterChange = ProductPassportVersion::query()->findOrFail($publishedVersion->getKey());

        $this->assertEquals($historicalPayload, $afterChange->payload);
        $this->assertSame($historicalHash, $afterChange->content_checksum);
        $this->assertSame($version1->uuid, $afterChange->payload['document_references'][0]['document_version_uuid']);
        $this->assertSame(str_repeat('1', 64), $afterChange->payload['document_references'][0]['checksum_sha256']);
    }

    /**
     * @return array{0: Company, 1: User, 2: Product}
     */
    private function context(): array
    {
        $company = Company::factory()->active()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = Product::query()->forceCreate([
            'uuid' => (string) str()->uuid(),
            'company_id' => $company->getKey(),
            'name' => 'R3.3 Upgrade Compatibility Product',
            'slug' => 'r3-3-upgrade-compatibility-'.str()->random(8),
            'slug_normalized' => 'r3-3-upgrade-compatibility-'.str()->random(8),
            'status' => ProductStatus::Active->value,
            'created_by' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$company, $user, $product];
    }
}
