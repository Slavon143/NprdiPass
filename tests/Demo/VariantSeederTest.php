<?php

use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use Database\Seeders\CatalogDemoSeeder;
use Database\Seeders\LocalDevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('demo variant set is stable tenant safe audit free and idempotent', function () {
    $this->seed(LocalDevelopmentSeeder::class);
    $this->seed(CatalogDemoSeeder::class);
    $company = Company::query()->where('name', 'NordiPass Demo AB')->sole();
    $expected = [
        'DEMO-GLOVE-PRO-M' => ['progrip-work-gloves', 'Medium', 'NS-GLOVE-PRO-M', true],
        'DEMO-GLOVE-PRO-L' => ['progrip-work-gloves', 'Large', 'NS-GLOVE-PRO-L', false],
        'DEMO-GLOVE-PRO-XL' => ['progrip-work-gloves', 'Extra Large', 'NS-GLOVE-PRO-XL', false],
        'DEMO-VEST-YELLOW-L' => ['reflective-safety-vest', 'Yellow / Large', 'NS-VEST-YL-L', true],
        'DEMO-VEST-YELLOW-M' => ['reflective-safety-vest', 'Yellow / Medium', 'NS-VEST-YL-M', false],
        'DEMO-VEST-ORANGE-L' => ['reflective-safety-vest', 'Orange / Large', 'NS-VEST-OR-L', false],
        'DEMO-FIRE-6KG' => ['fire-extinguisher-6kg', '6 kg', 'SG-FE-6KG', true],
        'DEMO-EAR-PRO' => ['professional-ear-defenders', 'Professional', 'SS-EAR-PRO', true],
        'DEMO-LAMP-40W' => ['industrial-led-work-lamp', '40 W', 'NL-WORK-40', true],
        'DEMO-LAMP-60W' => ['industrial-led-work-lamp', '60 W', 'NL-WORK-60', false],
    ];
    $variants = ProductVariant::query()
        ->forCompany($company)
        ->with('product')
        ->orderBy('id')
        ->get();
    $ids = $variants->pluck('id')->all();
    $defaultPointers = Product::query()->forCompany($company)->orderBy('id')->pluck('default_variant_id')->all();

    expect($variants)->toHaveCount(10)
        ->and($variants->pluck('sku')->unique())->toHaveCount(10)
        ->and($variants->every(fn (ProductVariant $variant): bool => $variant->company_id === $company->id
            && $variant->product->company_id === $company->id
            && $variant->gtin === null))->toBeTrue();

    foreach ($variants as $variant) {
        $specification = $expected[$variant->sku];
        expect($variant->product->slug)->toBe($specification[0])
            ->and($variant->name)->toBe($specification[1])
            ->and($variant->mpn)->toBe($specification[2])
            ->and($variant->isDefaultFor($variant->product))->toBe($specification[3]);
    }

    expect(AuditLog::query()->count())->toBe(0);
    $this->seed(CatalogDemoSeeder::class);

    expect(ProductVariant::query()->forCompany($company)->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(Product::query()->forCompany($company)->orderBy('id')->pluck('default_variant_id')->all())->toBe($defaultPointers)
        ->and(AuditLog::query()->count())->toBe(0);
});
