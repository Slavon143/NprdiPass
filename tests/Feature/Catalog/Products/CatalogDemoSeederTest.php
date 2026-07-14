<?php

use App\Enums\Catalog\ProductStatus;
use App\Models\AuditLog;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use Database\Seeders\CatalogDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalDevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('catalog_media'));

test('catalog demo seeder refuses a production environment', function () {
    $originalEnvironment = $this->app->environment();
    $this->app->instance('env', 'production');

    try {
        app(CatalogDemoSeeder::class)->run();
    } finally {
        $this->app->instance('env', $originalEnvironment);
    }

    expect(Category::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

test('catalog demo seeder uses only the dedicated company and is idempotent', function () {
    $unrelatedCompany = Company::factory()->create(['name' => 'Unrelated Company']);
    $this->seed(LocalDevelopmentSeeder::class);
    $this->seed(CatalogDemoSeeder::class);

    $demoCompany = Company::query()->where('name', 'NordiPass Demo AB')->sole();
    $categoryIds = Category::query()->forCompany($demoCompany)->orderBy('id')->pluck('id')->all();
    $productIds = Product::query()->forCompany($demoCompany)->orderBy('id')->pluck('id')->all();
    $variantIds = ProductVariant::query()->forCompany($demoCompany)->orderBy('id')->pluck('id')->all();
    $defaultPointers = Product::query()->forCompany($demoCompany)->orderBy('id')->pluck('default_variant_id')->all();

    expect($categoryIds)->toHaveCount(8)
        ->and($productIds)->toHaveCount(5)
        ->and($variantIds)->toHaveCount(10)
        ->and(Category::query()->forCompany($unrelatedCompany)->count())->toBe(0)
        ->and(Product::query()->forCompany($unrelatedCompany)->count())->toBe(0)
        ->and(ProductVariant::query()->forCompany($unrelatedCompany)->count())->toBe(0);

    $products = Product::query()
        ->forCompany($demoCompany)
        ->with(['defaultVariant', 'variants', 'categories'])
        ->orderBy('id')
        ->get();

    expect($products->pluck('slug')->all())->toBe([
        'progrip-work-gloves',
        'reflective-safety-vest',
        'fire-extinguisher-6kg',
        'professional-ear-defenders',
        'industrial-led-work-lamp',
    ]);
    $expectedVariantCounts = [3, 3, 1, 1, 2];

    foreach ($products as $index => $product) {
        expect($product->company_id)->toBe($demoCompany->id)
            ->and($product->status)->toBe(ProductStatus::Draft)
            ->and($product->published_at)->toBeNull()
            ->and($product->variants)->toHaveCount($expectedVariantCounts[$index])
            ->and($product->defaultVariant)->not->toBeNull()
            ->and($product->defaultVariant?->company_id)->toBe($demoCompany->id)
            ->and($product->defaultVariant?->product_id)->toBe($product->id)
            ->and($product->defaultVariant?->gtin)->toBeNull()
            ->and($product->variants->every(fn (ProductVariant $variant): bool => $variant->company_id === $demoCompany->id
                && $variant->product_id === $product->id
                && $variant->gtin === null))->toBeTrue()
            ->and($product->categories->pluck('id')->all())->toContain($product->primary_category_id);
    }

    $variants = $products->flatMap->variants;
    expect($variants->pluck('sku')->unique()->count())->toBe(10)
        ->and($variants->pluck('sku')->every(
            fn (?string $sku): bool => is_string($sku) && str_starts_with($sku, 'DEMO-'),
        ))->toBeTrue()
        ->and($variants->pluck('mpn')->filter())->toHaveCount(10)
        ->and(ProductMedia::query()->count())->toBe(9)
        ->and(AttributeDefinition::query()->forCompany($demoCompany)->count())->toBe(6)
        ->and(AttributeOption::query()->forCompany($demoCompany)->count())->toBe(14)
        ->and(ProductAttributeValue::query()->forCompany($demoCompany)->count())->toBe(7)
        ->and(VariantAttributeValue::query()->forCompany($demoCompany)->count())->toBe(14)
        ->and(AttributeDefinition::query()->forCompany($unrelatedCompany)->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    $mediaIds = ProductMedia::query()->orderBy('id')->pluck('id')->all();
    $mediaPaths = ProductMedia::query()->pluck('storage_path')->all();
    foreach ($mediaPaths as $path) {
        Storage::disk('catalog_media')->assertExists($path);
    }
    expect(ProductMedia::query()->whereNull('product_variant_id')->count())->toBe(5)
        ->and(ProductMedia::query()->whereNotNull('product_variant_id')->count())->toBe(4)
        ->and(Product::query()->forCompany($demoCompany)->whereNotNull('primary_media_id')->count())->toBe(3)
        ->and(ProductVariant::query()->forCompany($demoCompany)->whereNotNull('primary_media_id')->count())->toBe(4);

    $this->seed(CatalogDemoSeeder::class);

    expect(Category::query()->forCompany($demoCompany)->orderBy('id')->pluck('id')->all())->toBe($categoryIds)
        ->and(Product::query()->forCompany($demoCompany)->orderBy('id')->pluck('id')->all())->toBe($productIds)
        ->and(ProductVariant::query()->forCompany($demoCompany)->orderBy('id')->pluck('id')->all())->toBe($variantIds)
        ->and(Product::query()->forCompany($demoCompany)->orderBy('id')->pluck('default_variant_id')->all())->toBe($defaultPointers)
        ->and(AttributeDefinition::query()->forCompany($demoCompany)->count())->toBe(6)
        ->and(AttributeOption::query()->forCompany($demoCompany)->count())->toBe(14)
        ->and(ProductAttributeValue::query()->forCompany($demoCompany)->count())->toBe(7)
        ->and(VariantAttributeValue::query()->forCompany($demoCompany)->count())->toBe(14)
        ->and(ProductMedia::query()->orderBy('id')->pluck('id')->all())->toBe($mediaIds)
        ->and(ProductMedia::query()->pluck('storage_path')->all())->toBe($mediaPaths)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('normal testing database seeding does not install catalog demo records', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Company::query()->where('name', 'NordiPass Demo AB')->exists())->toBeTrue()
        ->and(Category::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});
