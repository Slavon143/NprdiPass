<?php

use App\Enums\Catalog\ProductStatus;
use App\Models\AuditLog;
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

uses(RefreshDatabase::class);

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

    expect($categoryIds)->toHaveCount(8)
        ->and($productIds)->toHaveCount(5)
        ->and($variantIds)->toHaveCount(5)
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

    foreach ($products as $product) {
        expect($product->company_id)->toBe($demoCompany->id)
            ->and($product->status)->toBe(ProductStatus::Draft)
            ->and($product->published_at)->toBeNull()
            ->and($product->primary_media_id)->toBeNull()
            ->and($product->variants)->toHaveCount(1)
            ->and($product->defaultVariant)->not->toBeNull()
            ->and($product->defaultVariant?->company_id)->toBe($demoCompany->id)
            ->and($product->defaultVariant?->product_id)->toBe($product->id)
            ->and($product->defaultVariant?->gtin)->toBeNull()
            ->and($product->defaultVariant?->primary_media_id)->toBeNull()
            ->and($product->categories->pluck('id')->all())->toContain($product->primary_category_id);
    }

    expect($products->pluck('defaultVariant.sku')->unique()->count())->toBe(5)
        ->and($products->pluck('defaultVariant.sku')->every(
            fn (?string $sku): bool => is_string($sku) && str_starts_with($sku, 'DEMO-'),
        ))->toBeTrue()
        ->and(ProductMedia::query()->count())->toBe(0)
        ->and(ProductAttributeValue::query()->count())->toBe(0)
        ->and(VariantAttributeValue::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);

    $this->seed(CatalogDemoSeeder::class);

    expect(Category::query()->forCompany($demoCompany)->orderBy('id')->pluck('id')->all())->toBe($categoryIds)
        ->and(Product::query()->forCompany($demoCompany)->orderBy('id')->pluck('id')->all())->toBe($productIds)
        ->and(ProductVariant::query()->forCompany($demoCompany)->orderBy('id')->pluck('id')->all())->toBe($variantIds)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('normal testing database seeding does not install catalog demo records', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Company::query()->where('name', 'NordiPass Demo AB')->exists())->toBeTrue()
        ->and(Category::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});
