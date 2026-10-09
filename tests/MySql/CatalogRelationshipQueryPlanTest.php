<?php

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('parent catalogue relations use bounded index lookups as related tables grow', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $company = Company::factory()->create();
    $category = DB::table('categories')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'Query fixture', 'slug' => 'query-fixture', 'slug_normalized' => 'query-fixture']);
    $definition = DB::table('attribute_definitions')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'name' => 'Tags', 'code' => 'query-tags', 'type' => 'multiselect', 'scope' => 'both']);
    $option = DB::table('attribute_options')->insertGetId(['company_id' => $company->id,
        'attribute_definition_id' => $definition, 'label' => 'Recycled', 'code' => 'recycled']);
    for ($batch = 0; $batch < 4; $batch++) {
        $rows = [];
        for ($row = 0; $row < 300; $row++) {
            $slug = 'query-product-'.($batch * 300 + $row);
            $rows[] = ['uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'name' => $slug,
                'slug' => $slug, 'slug_normalized' => $slug, 'status' => 'draft'];
        }
        DB::table('products')->insert($rows);
    }
    $products = DB::table('products')->where('company_id', $company->id)->pluck('id');
    foreach ($products->chunk(300) as $ids) {
        DB::table('product_variants')->insert($ids->map(fn ($id) => ['uuid' => (string) Str::uuid(),
            'company_id' => $company->id, 'product_id' => $id, 'is_default' => true])->all());
        DB::table('category_product')->insert($ids->map(fn ($id) => ['company_id' => $company->id,
            'product_id' => $id, 'category_id' => $category])->all());
        DB::table('product_attribute_values')->insert($ids->map(fn ($id) => ['company_id' => $company->id,
            'product_id' => $id, 'attribute_definition_id' => $definition])->all());
    }
    $variants = DB::table('product_variants')->where('company_id', $company->id)->pluck('id');
    foreach ($variants->chunk(300) as $ids) {
        DB::table('variant_attribute_values')->insert($ids->map(fn ($id) => ['company_id' => $company->id,
            'product_variant_id' => $id, 'attribute_definition_id' => $definition])->all());
    }
    foreach (['product', 'variant'] as $entity) {
        $values = DB::table($entity.'_attribute_values')->where('company_id', $company->id)->pluck('id');
        foreach ($values->chunk(300) as $ids) {
            DB::table($entity.'_attribute_value_options')->insert($ids->map(fn ($id) => ['company_id' => $company->id,
                'attribute_definition_id' => $definition, $entity.'_attribute_value_id' => $id,
                'attribute_option_id' => $option])->all());
        }
    }
    $product = Product::query()->forCompany($company)->findOrFail($products->last());
    $variant = ProductVariant::query()->forCompany($company)->where('product_id', $product->id)->sole();
    $productValue = ProductAttributeValue::query()->forCompany($company)->where('product_id', $product->id)->sole();
    $variantValue = VariantAttributeValue::query()->forCompany($company)->where('product_variant_id', $variant->id)->sole();
    $queries = ['product_attribute_values' => $product->attributeValues(), 'category_product' => $product->categories(),
        'product_attribute_value_options' => $productValue->selectedOptions(), 'variant_attribute_values' => $variant->attributeValues(),
        'variant_attribute_value_options' => $variantValue->selectedOptions()];
    foreach ($queries as $table => $query) {
        $plan = collect(DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()))->firstWhere('table', $table);
        expect($plan)->not->toBeNull();
        expect($plan->type)->not->toBe('ALL', 'Full scan in '.$table);
        expect((int) $plan->rows)->toBeLessThan(25);
        expect($query->get())->toHaveCount(1);
    }
    expect($product->attributeValues()->sole()->id)->toBe($productValue->id)
        ->and($variant->attributeValues()->sole()->id)->toBe($variantValue->id)
        ->and($productValue->selectedOptions()->sole()->id)->toBe($option)
        ->and($variantValue->selectedOptions()->sole()->id)->toBe($option)
        ->and($product->categories()->sole()->id)->toBe($category);
});
