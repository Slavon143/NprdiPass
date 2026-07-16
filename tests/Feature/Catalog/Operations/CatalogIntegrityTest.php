<?php

use App\Data\Catalog\Integrity\CatalogIntegrityIssue;
use App\Data\Catalog\Integrity\CatalogIntegrityReport;
use App\Enums\Catalog\CatalogIntegritySeverity;
use App\Models\Company;
use App\Services\Catalog\Integrity\CatalogIntegrityScanner;
use App\Services\Catalog\Integrity\Checks\AttributeIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\CategoryIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\IdentifierIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\LifecycleIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\MediaIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\ProductIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\TenantOwnershipIntegrityCheck;
use App\Services\Catalog\Integrity\Checks\VariantIntegrityCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function registerAllChecks(CatalogIntegrityScanner $scanner): void
{
    $scanner->addCheck(app(AttributeIntegrityCheck::class));
    $scanner->addCheck(app(CategoryIntegrityCheck::class));
    $scanner->addCheck(app(IdentifierIntegrityCheck::class));
    $scanner->addCheck(app(LifecycleIntegrityCheck::class));
    $scanner->addCheck(app(MediaIntegrityCheck::class));
    $scanner->addCheck(app(ProductIntegrityCheck::class));
    $scanner->addCheck(app(TenantOwnershipIntegrityCheck::class));
    $scanner->addCheck(app(VariantIntegrityCheck::class));
}

function createTestCompany(string $uuid, string $name = 'Test Company'): Company
{
    return Company::factory()->create([
        'uuid' => $uuid,
        'name' => $name,
    ]);
}

test('scanner detects cross-company category-parent relation', function () {
    $companyA = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa01', 'Company A');
    $companyB = createTestCompany('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb1', 'Company B');

    $parentBId = DB::table('categories')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc01',
        'company_id' => $companyB->id,
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Parent in B',
        'slug' => 'parent-in-b',
        'slug_normalized' => 'parent-in-b',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    $categoryAId = DB::table('categories')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc02',
        'company_id' => $companyA->id,
        'parent_id' => $parentBId,
        'depth' => 1,
        'name' => 'Child in A',
        'slug' => 'child-in-a',
        'slug_normalized' => 'child-in-a',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(CategoryIntegrityCheck::class));

    $report = $scanner->scanCompany($companyA);

    $crossParentIssues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.category.parent_tenant_mismatch',
    );

    expect($crossParentIssues)->not->toBeEmpty()
        ->and(array_values($crossParentIssues)[0]->severity)->toBe(CatalogIntegritySeverity::Critical);
});

test('scanner detects invalid default variant pointer', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa02');

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd01',
        'company_id' => $company->id,
        'name' => 'Broken Product',
        'slug' => 'broken-product',
        'slug_normalized' => 'broken-product',
        'status' => 'draft',
        'default_variant_id' => 99999,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(ProductIntegrityCheck::class));

    $report = $scanner->scanCompany($company);

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.product.default_variant_missing',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Error);
});

test('scanner detects default variant from wrong product', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa03');

    $productAId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd02',
        'company_id' => $company->id,
        'name' => 'Product A',
        'slug' => 'product-a',
        'slug_normalized' => 'product-a',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productBId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd03',
        'company_id' => $company->id,
        'name' => 'Product B',
        'slug' => 'product-b',
        'slug_normalized' => 'product-b',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantForBId = DB::table('product_variants')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee01',
        'company_id' => $company->id,
        'product_id' => $productBId,
        'name' => 'Variant for B',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::table('products')
        ->where('id', $productAId)
        ->update(['default_variant_id' => $variantForBId]);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(ProductIntegrityCheck::class));
    $scanner->addCheck(app(VariantIntegrityCheck::class));

    $report = $scanner->scanCompany($company);

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.product.default_variant_wrong_product',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Critical);
});

test('scanner detects archived default variant', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa04');

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd04',
        'company_id' => $company->id,
        'name' => 'Product',
        'slug' => 'product',
        'slug_normalized' => 'product',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee02',
        'company_id' => $company->id,
        'product_id' => $productId,
        'name' => 'Archived Variant',
        'status' => 'archived',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('products')
        ->where('id', $productId)
        ->update(['default_variant_id' => $variantId]);

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(VariantIntegrityCheck::class));

    $report = $scanner->scanCompany($company);

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.variant.default_archived',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Error);
});

test('scanner detects primary category missing from pivot', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa05');

    $categoryId = DB::table('categories')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc03',
        'company_id' => $company->id,
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Category',
        'slug' => 'category',
        'slug_normalized' => 'category',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd05',
        'company_id' => $company->id,
        'primary_category_id' => $categoryId,
        'name' => 'Product',
        'slug' => 'product',
        'slug_normalized' => 'product',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(CategoryIntegrityCheck::class));

    $report = $scanner->scanCompany($company);

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.category.product_primary_missing_from_pivot',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Error);
});

test('scanner detects primary media wrong owner', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa06');

    $productAId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd06',
        'company_id' => $company->id,
        'name' => 'Product A',
        'slug' => 'product-a',
        'slug_normalized' => 'product-a',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productBId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd07',
        'company_id' => $company->id,
        'name' => 'Product B',
        'slug' => 'product-b',
        'slug_normalized' => 'product-b',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mediaForBId = DB::table('product_media')->insertGetId([
        'uuid' => 'ffffffff-ffff-ffff-ffff-fffffffffff1',
        'company_id' => $company->id,
        'product_id' => $productBId,
        'original_filename' => 'test.jpg',
        'storage_path' => 'media/test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1024,
        'checksum_sha256' => str_repeat('a', 64),
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::table('products')
        ->where('id', $productAId)
        ->update(['primary_media_id' => $mediaForBId]);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(ProductIntegrityCheck::class));

    $report = $scanner->scanCompany($company);

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.product.primary_media_wrong_product',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Error);
});

test('scanner detects variant company mismatch', function () {
    $companyA = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa07', 'Company A');
    $companyB = createTestCompany('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb2', 'Company B');

    $productBId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd08',
        'company_id' => $companyB->id,
        'name' => 'Product in B',
        'slug' => 'product-in-b',
        'slug_normalized' => 'product-in-b',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    $variantId = DB::table('product_variants')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee03',
        'company_id' => $companyA->id,
        'product_id' => $productBId,
        'name' => 'Mismatched Variant',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(VariantIntegrityCheck::class));

    $report = $scanner->scanCompany($companyA);

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.variant.product_tenant_mismatch',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Critical);
});

test('scanner detects invalid GTIN check digit', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa08');

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd09',
        'company_id' => $company->id,
        'name' => 'GTIN Product',
        'slug' => 'gtin-product',
        'slug_normalized' => 'gtin-product',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('ALTER TABLE product_variants ALTER CHECK variants_gtin_format_check NOT ENFORCED');

    DB::table('product_variants')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee04',
        'company_id' => $company->id,
        'product_id' => $productId,
        'name' => 'Bad GTIN Variant',
        'gtin' => '12345',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(IdentifierIntegrityCheck::class));

    $report = $scanner->scanCompany($company);

    DB::table('product_variants')->where('uuid', 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee04')->delete();

    DB::statement('ALTER TABLE product_variants ALTER CHECK variants_gtin_format_check ENFORCED');

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.identifier.gtin_invalid_length',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Error);
});

test('scanner detects option from wrong definition', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa09');

    $def1Id = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'a1111111-1111-1111-1111-111111111111',
        'company_id' => $company->id,
        'name' => 'Color',
        'code' => 'color',
        'type' => 'select',
        'scope' => 'product',
        'required' => false,
        'filterable' => false,
        'searchable' => false,
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $def2Id = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'a2222222-2222-2222-2222-222222222222',
        'company_id' => $company->id,
        'name' => 'Size',
        'code' => 'size',
        'type' => 'select',
        'scope' => 'product',
        'required' => false,
        'filterable' => false,
        'searchable' => false,
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd10',
        'company_id' => $company->id,
        'name' => 'Option Mismatch',
        'slug' => 'option-mismatch',
        'slug_normalized' => 'option-mismatch',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $optionForDef2Id = DB::table('attribute_options')->insertGetId([
        'company_id' => $company->id,
        'attribute_definition_id' => $def2Id,
        'label' => 'Large',
        'code' => 'large',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::table('product_attribute_values')->insert([
        'company_id' => $company->id,
        'product_id' => $productId,
        'attribute_definition_id' => $def1Id,
        'value_option_id' => $optionForDef2Id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(AttributeIntegrityCheck::class));

    $report = $scanner->scanCompany($company);

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.attribute.select_option_wrong_definition',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Error);
});

test('scanner detects attribute scope mismatch', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa10');

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd11',
        'company_id' => $company->id,
        'name' => 'Scope Mismatch',
        'slug' => 'scope-mismatch',
        'slug_normalized' => 'scope-mismatch',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantDefId = DB::table('attribute_definitions')->insertGetId([
        'uuid' => 'a3333333-3333-3333-3333-333333333333',
        'company_id' => $company->id,
        'name' => 'Variant Only',
        'code' => 'variant-only',
        'type' => 'text',
        'scope' => 'variant',
        'required' => false,
        'filterable' => false,
        'searchable' => false,
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('product_attribute_values')->insert([
        'company_id' => $company->id,
        'product_id' => $productId,
        'attribute_definition_id' => $variantDefId,
        'value_text' => 'wrong scope',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(AttributeIntegrityCheck::class));

    $report = $scanner->scanCompany($company);

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.attribute.scope_excludes_owner',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Error);
});

test('scanner detects active product with readiness blockers', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa11');

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd12',
        'company_id' => $company->id,
        'name' => '',
        'slug' => 'not-ready',
        'slug_normalized' => 'not-ready',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(ProductIntegrityCheck::class));

    $report = $scanner->scanCompany($company);

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.product.active_not_ready',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Warning);
});

test('scanner detects tenant ownership mismatch', function () {
    $companyA = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa12', 'Company A');
    $companyB = createTestCompany('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb3', 'Company B');

    $productBId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd13',
        'company_id' => $companyB->id,
        'name' => 'Product in B',
        'slug' => 'product-in-b',
        'slug_normalized' => 'product-in-b',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::table('product_media')->insertGetId([
        'uuid' => 'ffffffff-ffff-ffff-ffff-fffffffffff2',
        'company_id' => $companyA->id,
        'product_id' => $productBId,
        'original_filename' => 'orphan.jpg',
        'storage_path' => 'media/orphan.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 512,
        'checksum_sha256' => str_repeat('b', 64),
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(TenantOwnershipIntegrityCheck::class));

    $report = $scanner->scanCompany($companyA);

    $issues = array_filter(
        $report->issues(),
        fn ($issue) => $issue->code === 'catalog.tenant.product_company_mismatch',
    );

    expect($issues)->not->toBeEmpty()
        ->and(array_values($issues)[0]->severity)->toBe(CatalogIntegritySeverity::Critical);
});

test('valid draft product has zero critical errors', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa13');

    $categoryId = DB::table('categories')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc04',
        'company_id' => $company->id,
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Valid Category',
        'slug' => 'valid-category',
        'slug_normalized' => 'valid-category',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd14',
        'company_id' => $company->id,
        'primary_category_id' => $categoryId,
        'name' => 'Valid Draft Product',
        'slug' => 'valid-draft-product',
        'slug_normalized' => 'valid-draft-product',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee05',
        'company_id' => $company->id,
        'product_id' => $productId,
        'name' => 'Draft Variant',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('products')
        ->where('id', $productId)
        ->update(['default_variant_id' => $variantId]);

    DB::table('category_product')->insert([
        'category_id' => $categoryId,
        'product_id' => $productId,
        'company_id' => $company->id,
        'created_at' => now(),
    ]);

    $scanner = new CatalogIntegrityScanner;
    registerAllChecks($scanner);

    $report = $scanner->scanCompany($company);

    expect($report->critical())->toBe(0);
});

test('valid active product has zero critical errors', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa14');

    $categoryId = DB::table('categories')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc05',
        'company_id' => $company->id,
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Active Category',
        'slug' => 'active-category',
        'slug_normalized' => 'active-category',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd15',
        'company_id' => $company->id,
        'primary_category_id' => $categoryId,
        'name' => 'Valid Active Product',
        'slug' => 'valid-active-product',
        'slug_normalized' => 'valid-active-product',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee06',
        'company_id' => $company->id,
        'product_id' => $productId,
        'name' => 'Active Variant',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('products')
        ->where('id', $productId)
        ->update(['default_variant_id' => $variantId]);

    DB::table('category_product')->insert([
        'category_id' => $categoryId,
        'product_id' => $productId,
        'company_id' => $company->id,
        'created_at' => now(),
    ]);

    $scanner = new CatalogIntegrityScanner;
    registerAllChecks($scanner);

    $report = $scanner->scanCompany($company);

    expect($report->critical())->toBe(0);
});

test('valid archived product has zero critical errors', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa15');

    $categoryId = DB::table('categories')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc06',
        'company_id' => $company->id,
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Archived Category',
        'slug' => 'archived-category',
        'slug_normalized' => 'archived-category',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = DB::table('products')->insertGetId([
        'uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddd16',
        'company_id' => $company->id,
        'primary_category_id' => $categoryId,
        'name' => 'Valid Archived Product',
        'slug' => 'valid-archived-product',
        'slug_normalized' => 'valid-archived-product',
        'status' => 'archived',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId = DB::table('product_variants')->insertGetId([
        'uuid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeee07',
        'company_id' => $company->id,
        'product_id' => $productId,
        'name' => 'Archived Variant',
        'status' => 'archived',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('products')
        ->where('id', $productId)
        ->update(['default_variant_id' => $variantId]);

    DB::table('category_product')->insert([
        'category_id' => $categoryId,
        'product_id' => $productId,
        'company_id' => $company->id,
        'created_at' => now(),
    ]);

    $scanner = new CatalogIntegrityScanner;
    registerAllChecks($scanner);

    $report = $scanner->scanCompany($company);

    expect($report->critical())->toBe(0);
});

test('scanner supports company scope', function () {
    $companyA = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa16', 'Company A');
    $companyB = createTestCompany('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb4', 'Company B');

    $categoryBId = DB::table('categories')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc07',
        'company_id' => $companyB->id,
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Category B',
        'slug' => 'category-b',
        'slug_normalized' => 'category-b',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::table('categories')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc08',
        'company_id' => $companyA->id,
        'parent_id' => $categoryBId,
        'depth' => 1,
        'name' => 'Category A with B parent',
        'slug' => 'category-a-b-parent',
        'slug_normalized' => 'category-a-b-parent',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $scanner = new CatalogIntegrityScanner;
    $scanner->addCheck(app(CategoryIntegrityCheck::class));

    $reportA = $scanner->scanCompany($companyA);
    $reportB = $scanner->scanCompany($companyB);

    $issuesA = array_filter(
        $reportA->issues(),
        fn ($issue) => $issue->code === 'catalog.category.parent_tenant_mismatch',
    );

    $issuesB = array_filter(
        $reportB->issues(),
        fn ($issue) => $issue->code === 'catalog.category.parent_tenant_mismatch',
    );

    expect($issuesA)->not->toBeEmpty();
    expect($issuesB)->toBeEmpty();
});

test('scanner severity threshold filters correctly', function () {
    $report = new CatalogIntegrityReport;

    $report->addIssue(new CatalogIntegrityIssue(
        code: 'test.info',
        severity: CatalogIntegritySeverity::Info,
        companyUuid: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa99',
        resourceType: 'test',
        resourceUuid: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        message: 'Info message',
    ));

    $report->addIssue(new CatalogIntegrityIssue(
        code: 'test.warning',
        severity: CatalogIntegritySeverity::Warning,
        companyUuid: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa99',
        resourceType: 'test',
        resourceUuid: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        message: 'Warning message',
    ));

    $report->addIssue(new CatalogIntegrityIssue(
        code: 'test.error',
        severity: CatalogIntegritySeverity::Error,
        companyUuid: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa99',
        resourceType: 'test',
        resourceUuid: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        message: 'Error message',
    ));

    expect($report->hasIssuesAtOrAbove(CatalogIntegritySeverity::Info))->toBeTrue();
    expect($report->hasIssuesAtOrAbove(CatalogIntegritySeverity::Warning))->toBeTrue();
    expect($report->hasIssuesAtOrAbove(CatalogIntegritySeverity::Error))->toBeTrue();
    expect($report->hasIssuesAtOrAbove(CatalogIntegritySeverity::Critical))->toBeFalse();
});

test('scanner is read-only and makes no changes', function () {
    $company = createTestCompany('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaa17');

    $categoryId = DB::table('categories')->insertGetId([
        'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccc09',
        'company_id' => $company->id,
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Readonly Category',
        'slug' => 'readonly-category',
        'slug_normalized' => 'readonly-category',
        'sort_order' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $beforeCategories = DB::table('categories')->where('company_id', $company->id)->count();
    $beforeProducts = DB::table('products')->where('company_id', $company->id)->count();
    $beforeVariants = DB::table('product_variants')->where('company_id', $company->id)->count();

    $scanner = new CatalogIntegrityScanner;
    registerAllChecks($scanner);

    $scanner->scanCompany($company);

    $afterCategories = DB::table('categories')->where('company_id', $company->id)->count();
    $afterProducts = DB::table('products')->where('company_id', $company->id)->count();
    $afterVariants = DB::table('product_variants')->where('company_id', $company->id)->count();

    expect($afterCategories)->toBe($beforeCategories);
    expect($afterProducts)->toBe($beforeProducts);
    expect($afterVariants)->toBe($beforeVariants);
});
