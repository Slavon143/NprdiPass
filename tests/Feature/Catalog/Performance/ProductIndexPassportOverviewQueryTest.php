<?php

namespace Tests\Feature\Catalog\Performance;

use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Enums\Passports\ProductPassportStatus;
use App\Enums\Passports\ProductPassportVersionStatus;
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
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ProductIndexPassportOverviewQueryTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY_BUDGET = 320;

    private Company $company;

    private User $owner;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['status' => CompanyStatus::Active]);
        $this->owner = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'company_id' => $this->company->getKey(),
            'user_id' => $this->owner->getKey(),
            'role' => CompanyRole::Owner,
        ]);

        $this->actingAs($this->owner);
        app(CurrentCompany::class)->set($this->company);

        View::share('currentCompany', $this->company);
        View::share('availableCompanies', new Collection([$this->company]));
        View::share('currentMembership', $this->owner->memberships()
            ->where('company_id', $this->company->getKey())
            ->first());
        View::share('slot', '');

        $this->category = new Category;
        $this->category->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => 'Perf Index Category',
            'slug' => 'perf-index-category',
            'slug_normalized' => 'perf-index-category',
            'depth' => 0,
            'sort_order' => 0,
            'status' => CategoryStatus::Active,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    private function seedPerformanceData(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $product = $this->createProductWithRelations("Perf Product {$i}");
            $passport = $this->createPassport($product);

            $draftVersion = $this->createDraftVersion($passport);

            $passport->forceFill(['current_draft_version_id' => $draftVersion->getKey()])->save();
        }
    }

    private function createProductWithRelations(string $name): Product
    {
        $slug = str($name)->slug()->toString();

        $product = new Product;
        $product->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'name' => $name,
            'slug' => $slug,
            'slug_normalized' => $slug,
            'brand' => 'PerfBrand',
            'manufacturer' => 'PerfMfr',
            'primary_category_id' => $this->category->getKey(),
            'status' => ProductStatus::Active,
            'created_by' => $this->owner->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        for ($v = 1; $v <= 5; $v++) {
            $variant = new ProductVariant;
            $variant->forceFill([
                'uuid' => (string) str()->uuid(),
                'company_id' => $this->company->getKey(),
                'product_id' => $product->getKey(),
                'name' => "Variant {$v}",
                'sku' => "SKU-PERF-{$slug}-{$v}",
                'status' => ProductVariantStatus::Active,
                'sort_order' => $v,
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            if ($v === 1) {
                $product->forceFill(['default_variant_id' => $variant->getKey()])->save();
            }
        }

        for ($m = 1; $m <= 5; $m++) {
            $media = new ProductMedia;
            $media->forceFill([
                'uuid' => (string) str()->uuid(),
                'company_id' => $this->company->getKey(),
                'product_id' => $product->getKey(),
                'original_filename' => "perf-{$slug}-{$m}.jpg",
                'mime_type' => 'image/jpeg',
                'size_bytes' => 2048,
                'storage_path' => "catalog/perf/{$slug}-{$m}.jpg",
                'checksum_sha256' => str_repeat(dechex($m), 64),
                'sort_order' => $m,
                'uploaded_by' => $this->owner->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            if ($m === 1) {
                $product->forceFill(['primary_media_id' => $media->getKey()])->save();
            }
        }

        for ($d = 1; $d <= 5; $d++) {
            $document = new ProductDocument;
            $document->forceFill([
                'uuid' => (string) str()->uuid(),
                'company_id' => $this->company->getKey(),
                'product_id' => $product->getKey(),
                'status' => ProductDocumentStatus::Active,
                'created_by_user_id' => $this->owner->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            $docVersion = new ProductDocumentVersion;
            $docVersion->forceFill([
                'uuid' => (string) str()->uuid(),
                'company_id' => $this->company->getKey(),
                'document_id' => $document->getKey(),
                'version_number' => 1,
                'document_type' => ProductDocumentType::Instruction->value,
                'title' => "Perf Doc {$d} for {$name}",
                'language' => 'sv',
                'visibility' => ProductDocumentVisibility::Internal->value,
                'original_filename' => "perf-doc-{$slug}-{$d}.pdf",
                'mime_type' => 'application/pdf',
                'file_extension' => 'pdf',
                'size_bytes' => 1024,
                'checksum_sha256' => str_repeat('b', 64),
                'storage_key' => "companies/perf/documents/{$slug}-{$d}.pdf",
                'created_by_user_id' => $this->owner->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            $document->forceFill(['current_version_id' => $docVersion->getKey()])->save();
        }

        $product->categories()->attach($this->category->getKey(), [
            'company_id' => $this->company->getKey(),
            'created_at' => now(),
        ]);

        return $product->refresh();
    }

    private function createPassport(Product $product): ProductPassport
    {
        $passport = new ProductPassport;
        $passport->forceFill([
            'uuid' => (string) str()->uuid(),
            'public_id' => (string) Uuid::uuid7(),
            'company_id' => $this->company->getKey(),
            'product_id' => $product->getKey(),
            'status' => ProductPassportStatus::Draft,
            'default_language' => 'sv',
            'enabled_languages' => ['sv', 'en'],
            'created_by' => $this->owner->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        return $passport;
    }

    private function createDraftVersion(ProductPassport $passport): ProductPassportVersion
    {
        $version = new ProductPassportVersion;
        $version->forceFill([
            'uuid' => (string) str()->uuid(),
            'company_id' => $this->company->getKey(),
            'passport_id' => $passport->getKey(),
            'status' => ProductPassportVersionStatus::Draft,
            'version_number' => null,
            'draft_revision' => 1,
            'schema_version' => '1.0',
            'payload' => [
                'sections' => [
                    'identity' => [
                        'public_name' => 'Perf Product Name',
                        'public_description' => 'Perf product description.',
                    ],
                ],
            ],
            'content_checksum' => null,
            'published_at' => null,
            'published_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ])->save();

        return $version;
    }

    public function test_query_count_bounded(): void
    {
        $this->seedPerformanceData();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('catalog.products.index', ['per_page' => 25]));

        $queries = DB::getQueryLog();

        $this->assertLessThanOrEqual(
            self::QUERY_BUDGET,
            count($queries),
            sprintf('Product index query count %d exceeds budget of %d.', count($queries), self::QUERY_BUDGET),
        );
    }

    public function test_no_single_product_query(): void
    {
        $this->seedPerformanceData();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('catalog.products.index', ['per_page' => 25]));

        $queries = DB::getQueryLog();

        foreach ($queries as $query) {
            $sql = $query['query'];
            $bindings = array_map(fn ($b) => is_string($b) ? $b : var_export($b, true), $query['bindings'] ?? []);

            $queryString = vsprintf(str_replace('?', '%s', $sql), $bindings);

            $this->assertStringNotContainsString(
                'product_id = 1',
                $queryString,
                'Found isolated single-product query: '.$queryString,
            );

            $this->assertStringNotContainsString(
                '"product_id" = 1',
                $queryString,
                'Found isolated single-product quoted query: '.$queryString,
            );
        }
    }

    public function test_no_pdf_reads(): void
    {
        Storage::fake('product_documents');

        $this->seedPerformanceData();

        $this->get(route('catalog.products.index', ['per_page' => 25]))
            ->assertOk();

        Storage::disk('product_documents')->assertMissing('non-existent-file.pdf');
    }
}
