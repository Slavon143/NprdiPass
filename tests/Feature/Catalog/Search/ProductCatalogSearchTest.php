<?php

use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\ProductCategoryService;
use App\Support\Catalog\CatalogIdentifierNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{User, Company} */
function r110Context(CompanyRole $role = CompanyRole::Owner): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user,
        'company_id' => $company,
        'role' => $role,
    ]);
    test()->actingAs($user);

    return [$user, $company];
}

function r110Category(Company $company, User $actor, string $name, ?Category $parent = null): Category
{
    $slug = str($name)->slug()->toString();
    $category = new Category;
    $category->forceFill([
        'company_id' => $company->id,
        'parent_id' => $parent?->id,
        'depth' => $parent === null ? 0 : $parent->depth + 1,
        'name' => $name,
        'slug' => $slug,
        'slug_normalized' => $slug,
        'description' => null,
        'sort_order' => 10,
        'status' => CategoryStatus::Active,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $category->refresh();
}

function r110Product(
    Company $company,
    User $actor,
    string $name,
    ?Category $primary = null,
    array $overrides = [],
    array $variantOverrides = [],
): Product {
    $normalizer = app(CatalogIdentifierNormalizer::class);
    $slug = $overrides['slug'] ?? str($name)->slug()->toString();
    $sku = $variantOverrides['sku'] ?? 'R110-'.str($slug)->upper()->replace('-', '-')->toString();
    $product = app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => $name,
        'slug' => $slug,
        'short_description' => null,
        'description' => null,
        'brand' => $overrides['brand'] ?? null,
        'manufacturer' => $overrides['manufacturer'] ?? null,
    ], [
        'name' => $variantOverrides['name'] ?? 'Default',
        'sku' => $sku,
        'sku_normalized' => $sku === null ? null : $normalizer->normalizeSku($sku),
        'gtin' => $variantOverrides['gtin'] ?? null,
        'mpn' => $variantOverrides['mpn'] ?? null,
        'sort_order' => 0,
    ]);

    if ($primary !== null) {
        app(ProductCategoryService::class)->sync($company, $product, $primary->uuid, []);
    }

    if (isset($overrides['status'])) {
        $product->forceFill(['status' => $overrides['status']])->save();
    }

    if (isset($variantOverrides['status'])) {
        $product->defaultVariant?->forceFill(['status' => $variantOverrides['status']])->save();
    }

    return $product->refresh()->load('defaultVariant');
}

function r110Variant(Company $company, User $actor, Product $product, string $name, string $sku, array $overrides = []): ProductVariant
{
    $normalizer = app(CatalogIdentifierNormalizer::class);
    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'name' => $name,
        'sku' => $sku,
        'sku_normalized' => $normalizer->normalizeSku($sku),
        'gtin' => $overrides['gtin'] ?? null,
        'mpn' => $overrides['mpn'] ?? null,
        'status' => $overrides['status'] ?? ProductVariantStatus::Draft,
        'sort_order' => $overrides['sort_order'] ?? 10,
        'primary_media_id' => null,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $variant->refresh();
}

function r110Definition(Company $company, User $actor, string $code, AttributeDataType $type, AttributeScope $scope, bool $required = false): AttributeDefinition
{
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $company->id,
        'name' => str($code)->replace('_', ' ')->title()->toString(),
        'code' => $code,
        'description' => null,
        'type' => $type,
        'scope' => $scope,
        'unit' => null,
        'required' => $required,
        'filterable' => true,
        'searchable' => false,
        'validation_rules' => null,
        'sort_order' => 10,
        'status' => AttributeDefinitionStatus::Active,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $definition->refresh();
}

function r110Option(Company $company, AttributeDefinition $definition, string $code): AttributeOption
{
    $option = new AttributeOption;
    $option->forceFill([
        'company_id' => $company->id,
        'attribute_definition_id' => $definition->id,
        'label' => str($code)->replace('_', ' ')->title()->toString(),
        'code' => $code,
        'sort_order' => 10,
        'status' => AttributeOptionStatus::Active,
    ])->save();

    return $option->refresh();
}

function r110ProductValue(Company $company, Product $product, AttributeDefinition $definition, array $columns, array $optionIds = []): void
{
    $value = new ProductAttributeValue;
    $value->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'attribute_definition_id' => $definition->id,
        'value_text' => null,
        'value_integer' => null,
        'value_decimal' => null,
        'value_boolean' => null,
        'value_date' => null,
        'value_option_id' => null,
        ...$columns,
    ])->save();

    foreach ($optionIds as $optionId) {
        DB::table('product_attribute_value_options')->insert([
            'company_id' => $company->id,
            'attribute_definition_id' => $definition->id,
            'product_attribute_value_id' => $value->id,
            'attribute_option_id' => $optionId,
            'created_at' => now(),
        ]);
    }
}

function r110VariantValue(Company $company, ProductVariant $variant, AttributeDefinition $definition, array $columns, array $optionIds = []): void
{
    $value = new VariantAttributeValue;
    $value->forceFill([
        'company_id' => $company->id,
        'product_variant_id' => $variant->id,
        'attribute_definition_id' => $definition->id,
        'value_text' => null,
        'value_integer' => null,
        'value_decimal' => null,
        'value_boolean' => null,
        'value_date' => null,
        'value_option_id' => null,
        ...$columns,
    ])->save();

    foreach ($optionIds as $optionId) {
        DB::table('variant_attribute_value_options')->insert([
            'company_id' => $company->id,
            'attribute_definition_id' => $definition->id,
            'variant_attribute_value_id' => $value->id,
            'attribute_option_id' => $optionId,
            'created_at' => now(),
        ]);
    }
}

test('default listing is tenant scoped and hides archived products unless explicitly selected', function () {
    [$viewer, $company] = r110Context(CompanyRole::Viewer);
    $category = r110Category($company, $viewer, 'Workwear');
    r110Product($company, $viewer, 'Draft Gloves', $category, ['brand' => 'SafeHand']);
    r110Product($company, $viewer, 'Active Vest', $category, ['status' => ProductStatus::Active, 'brand' => 'SafeHand'], ['status' => ProductVariantStatus::Active]);
    r110Product($company, $viewer, 'Archived Ear Defenders', $category, ['status' => ProductStatus::Archived]);
    r110Product(Company::factory()->create(), $viewer, 'Foreign Secret');

    $this->get(route('catalog.products.index'))
        ->assertOk()
        ->assertSee('Draft Gloves')
        ->assertSee('Active Vest')
        ->assertDontSee('Archived Ear Defenders')
        ->assertDontSee('Foreign Secret')
        ->assertDontSee('Create product')
        ->assertSee('2 products found');

    $this->get(route('catalog.products.index', ['product_statuses' => ['archived']]))
        ->assertOk()
        ->assertSee('Archived Ear Defenders')
        ->assertDontSee('Draft Gloves');
});

test('keyword search finds product variant identifier brand manufacturer and category fields with exact identifier priority', function () {
    [$owner, $company] = r110Context();
    $gloves = r110Category($company, $owner, 'Safety Gloves');
    $boots = r110Category($company, $owner, 'Boots');
    $target = r110Product($company, $owner, 'ProGrip Work Gloves', $gloves, [
        'brand' => 'SafeHand',
        'manufacturer' => 'Nordi Safety AB',
    ], [
        'name' => 'Large Black',
        'sku' => 'DEMO-GLOVE-PRO-L',
        'gtin' => '7350012345678',
        'mpn' => 'NS-GLOVE-PRO-L',
    ]);
    r110Product($company, $owner, 'Generic Safety Gloves', $boots, ['brand' => 'Other']);

    foreach (['progrip', 'progrip-work-gloves', 'safehand', 'Nordi Safety', 'Large Black', 'DEMO-GLOVE-PRO-L', '7350012345678', 'NS-GLOVE-PRO-L', 'Safety Gloves'] as $term) {
        $this->get(route('catalog.products.index', ['q' => '  '.$term.'  ', 'sort' => 'relevance']))
            ->assertOk()
            ->assertSee('ProGrip Work Gloves');
    }

    $this->get(route('catalog.products.index', ['q' => 'DEMO-GLOVE-PRO-L', 'sort' => 'relevance']))
        ->assertViewHas('products', fn (LengthAwarePaginator $products): bool => $products->first()?->is($target) === true);
});

test('category brand manufacturer status missing and readiness filters compose', function () {
    [$owner, $company] = r110Context();
    $parent = r110Category($company, $owner, 'Safety');
    $child = r110Category($company, $owner, 'Hand Protection', $parent);
    $other = r110Category($company, $owner, 'Lighting');
    $match = r110Product($company, $owner, 'Filtered Gloves', $child, ['brand' => 'SafeHand', 'manufacturer' => 'Nordi Safety AB']);
    r110Product($company, $owner, 'Wrong Category Lamp', $other, ['brand' => 'SafeHand', 'manufacturer' => 'Nordi Safety AB']);
    r110Product($company, $owner, 'Wrong Brand Gloves', $child, ['brand' => 'Other', 'manufacturer' => 'Nordi Safety AB']);
    r110Product($company, $owner, 'Archived Variant Product', $child, ['brand' => 'SafeHand', 'manufacturer' => 'Nordi Safety AB'], ['status' => ProductVariantStatus::Archived]);

    $this->get(route('catalog.products.index', [
        'category_uuids' => [$parent->uuid],
        'category_mode' => 'primary',
        'include_descendants' => '1',
        'brand' => 'SafeHand',
        'manufacturer' => 'Nordi Safety AB',
        'missing_data' => ['primary_image'],
        'readiness' => 'not_ready',
    ]))
        ->assertOk()
        ->assertSee('Filtered Gloves')
        ->assertDontSee('Wrong Category Lamp')
        ->assertDontSee('Wrong Brand Gloves');

    $this->get(route('catalog.products.index', ['variant_statuses' => ['archived']]))
        ->assertOk()
        ->assertSee('Archived Variant Product')
        ->assertDontSee('Filtered Gloves');
});

test('attribute filters support select multiselect boolean numeric decimal and date without duplicate products', function () {
    [$owner, $company] = r110Context();
    $category = r110Category($company, $owner, 'Attributes');
    $match = r110Product($company, $owner, 'Attribute Match', $category);
    r110Variant($company, $owner, $match, 'Second matching variant', 'R110-SECOND-MATCH');
    $miss = r110Product($company, $owner, 'Attribute Miss', $category);

    $material = r110Definition($company, $owner, 'material', AttributeDataType::Select, AttributeScope::Product);
    $certs = r110Definition($company, $owner, 'certifications', AttributeDataType::Multiselect, AttributeScope::Product);
    $waterproof = r110Definition($company, $owner, 'waterproof', AttributeDataType::Boolean, AttributeScope::Both);
    $weight = r110Definition($company, $owner, 'weight', AttributeDataType::Decimal, AttributeScope::Product);
    $power = r110Definition($company, $owner, 'power', AttributeDataType::Integer, AttributeScope::Variant);
    $available = r110Definition($company, $owner, 'available_from', AttributeDataType::Date, AttributeScope::Product);
    $nitrile = r110Option($company, $material, 'nitrile');
    $steel = r110Option($company, $material, 'steel');
    $ce = r110Option($company, $certs, 'ce');

    r110ProductValue($company, $match, $material, ['value_option_id' => $nitrile->id]);
    r110ProductValue($company, $match, $certs, [], [$ce->id]);
    r110ProductValue($company, $match, $waterproof, ['value_boolean' => true]);
    r110ProductValue($company, $match, $weight, ['value_decimal' => '5.2500']);
    r110ProductValue($company, $match, $available, ['value_date' => '2026-07-14']);
    r110VariantValue($company, $match->defaultVariant, $power, ['value_integer' => 60]);

    r110ProductValue($company, $miss, $material, ['value_option_id' => $steel->id]);
    r110ProductValue($company, $miss, $weight, ['value_decimal' => '15.0000']);

    $this->get(route('catalog.products.index', [
        'attributes' => [
            $material->uuid => ['definition' => $material->uuid, 'options' => [$nitrile->id]],
            $certs->uuid => ['definition' => $certs->uuid, 'options' => [$ce->id]],
            $waterproof->uuid => ['definition' => $waterproof->uuid, 'boolean' => '1'],
            $weight->uuid => ['definition' => $weight->uuid, 'min' => '5.0', 'max' => '6.0'],
            $power->uuid => ['definition' => $power->uuid, 'min' => '40', 'max' => '100'],
            $available->uuid => ['definition' => $available->uuid, 'from' => '2026-01-01', 'to' => '2026-12-31'],
        ],
    ]))
        ->assertOk()
        ->assertSee('Attribute Match')
        ->assertDontSee('Attribute Miss')
        ->assertViewHas('products', fn (LengthAwarePaginator $products): bool => $products->total() === 1);
});

test('sorting pagination and query strings are stable', function () {
    [$owner, $company] = r110Context();
    $category = r110Category($company, $owner, 'Stable');
    $timestamp = now()->subDay();

    foreach (range(1, 30) as $index) {
        $product = r110Product($company, $owner, sprintf('Stable Product %02d', $index), $category, ['brand' => 'StableBrand']);
        $product->forceFill(['updated_at' => $timestamp, 'created_at' => $timestamp])->save();
    }

    $first = $this->get(route('catalog.products.index', ['brand' => 'StableBrand', 'sort' => 'name', 'direction' => 'asc', 'per_page' => 25, 'page' => 1]));
    $second = $this->get(route('catalog.products.index', ['brand' => 'StableBrand', 'sort' => 'name', 'direction' => 'asc', 'per_page' => 25, 'page' => 2]));

    $first->assertOk()->assertSee('brand=StableBrand', false);
    $second->assertOk();
    $firstIds = $first->viewData('products')->getCollection()->pluck('id')->all();
    $secondIds = $second->viewData('products')->getCollection()->pluck('id')->all();

    expect(array_intersect($firstIds, $secondIds))->toBe([])
        ->and(count($firstIds))->toBe(25)
        ->and(count($secondIds))->toBe(5);
});

test('invalid and wrong tenant filters are rejected without leaking data', function () {
    [$owner, $company] = r110Context();
    $otherCompany = Company::factory()->create();
    $foreignCategory = r110Category($otherCompany, $owner, 'Foreign Category');
    $foreignDefinition = r110Definition($otherCompany, $owner, 'foreign_select', AttributeDataType::Select, AttributeScope::Product);
    $foreignOption = r110Option($otherCompany, $foreignDefinition, 'foreign');

    $this->from(route('catalog.products.index'))->get(route('catalog.products.index', [
        'sort' => 'raw_sql',
    ]))->assertRedirect(route('catalog.products.index'))
        ->assertSessionHasErrors('sort');

    $this->from(route('catalog.products.index'))->get(route('catalog.products.index', [
        'category_uuids' => [$foreignCategory->uuid],
    ]))->assertRedirect(route('catalog.products.index'))
        ->assertSessionHasErrors('category_uuids');

    $this->from(route('catalog.products.index'))->get(route('catalog.products.index', [
        'attributes' => [
            $foreignDefinition->uuid => ['definition' => $foreignDefinition->uuid, 'options' => [$foreignOption->id]],
        ],
    ]))->assertRedirect(route('catalog.products.index'))
        ->assertSessionHasErrors('attributes.0.definition');
});
