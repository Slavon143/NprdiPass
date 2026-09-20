<?php

use App\Actions\Catalog\Attributes\SyncProductAttributeValuesAction;
use App\Actions\Catalog\Attributes\SyncVariantAttributeValuesAction;
use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyRole;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;

/** @return array{User, Company, Product, ProductVariant} */
function r18UsageContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $actor, 'company_id' => $company, 'role' => $role]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);
    $product = app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => 'Usage Product', 'slug' => 'usage-product', 'short_description' => null,
        'description' => null, 'brand' => null, 'manufacturer' => null,
    ], [
        'name' => 'Default', 'sku' => null, 'sku_normalized' => null, 'gtin' => null, 'mpn' => null, 'sort_order' => 0,
    ]);

    return [$actor, $company, $product, $product->defaultVariant()->firstOrFail()];
}

function r18UsageDefinition(Company $company, User $actor, string $code, AttributeScope $scope, string $type = 'text', ?string $unit = null): AttributeDefinition
{
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $company->id, 'name' => str($code)->headline()->toString(), 'code' => $code,
        'description' => null, 'type' => AttributeDataType::from($type), 'scope' => $scope, 'unit' => $unit,
        'required' => false, 'filterable' => false, 'searchable' => false, 'validation_rules' => null,
        'sort_order' => 10, 'status' => AttributeDefinitionStatus::Active, 'created_by' => $actor->id, 'updated_by' => $actor->id,
    ])->save();

    return $definition->refresh();
}

function r18UsageOption(Company $company, AttributeDefinition $definition, string $code): AttributeOption
{
    $option = new AttributeOption;
    $option->forceFill([
        'company_id' => $company->id, 'attribute_definition_id' => $definition->id,
        'label' => str($code)->headline()->toString(), 'code' => $code, 'sort_order' => 10, 'status' => AttributeOptionStatus::Active,
    ])->save();

    return $option->refresh();
}

test('attribute index shows human readable usage counts per entity kind', function () {
    [$actor, $company, $product, $variant] = r18UsageContext();
    $text = r18UsageDefinition($company, $actor, 'material', AttributeScope::Product);
    $select = r18UsageDefinition($company, $actor, 'colour', AttributeScope::Product, 'select');
    $variantOnly = r18UsageDefinition($company, $actor, 'size', AttributeScope::Variant);
    $unused = r18UsageDefinition($company, $actor, 'fire_rating', AttributeScope::Product);
    $black = r18UsageOption($company, $select, 'black');
    $white = r18UsageOption($company, $select, 'white');
    r18UsageOption($company, $select, 'red');

    $second = app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => 'Second Product', 'slug' => 'second-product', 'short_description' => null,
        'description' => null, 'brand' => null, 'manufacturer' => null,
    ], ['name' => 'Default', 'sku' => null, 'sku_normalized' => null, 'gtin' => null, 'mpn' => null, 'sort_order' => 0]);

    app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $product, [$text->uuid => 'Steel', $select->uuid => $black->id]);
    app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $second, [$text->uuid => 'ABS', $select->uuid => $white->id]);
    app(SyncVariantAttributeValuesAction::class)->execute($actor, $company, $product, $variant, [$variantOnly->uuid => 'Large']);

    $this->get(route('catalog.attributes.index'))
        ->assertOk()
        ->assertSeeText('Used by 2 products')
        ->assertSeeText('3 options · used by 2 products')
        ->assertSeeText('Used by 1 variant')
        ->assertSeeText('View usage');
});

test('attribute index hides the technical code when it matches the name slug', function () {
    [$actor, $company] = r18UsageContext();
    $matching = r18UsageDefinition($company, $actor, 'battery_voltage', AttributeScope::Product);
    $diverging = r18UsageDefinition($company, $actor, 'color', AttributeScope::Product);
    $diverging->forceFill(['name' => 'Colour'])->save();

    $this->get(route('catalog.attributes.index'))
        ->assertOk()
        ->assertDontSeeText('battery_voltage')
        ->assertSeeText('color');
});

test('attribute usage page lists products and variants with their formatted values', function () {
    [$actor, $company, $product, $variant] = r18UsageContext();
    $weight = r18UsageDefinition($company, $actor, 'weight', AttributeScope::Both, 'text', 'kg');
    app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $product, [$weight->uuid => '2.1']);
    app(SyncVariantAttributeValuesAction::class)->execute($actor, $company, $product, $variant, [$weight->uuid => '1.8']);

    $this->get(route('catalog.attributes.usage', $weight->uuid))
        ->assertOk()
        ->assertSee('Usage Product')
        ->assertSee('Default')
        ->assertSee('2.1 kg')
        ->assertSee('1.8 kg');
});

test('attribute usage page is tenant scoped and visible to viewers', function () {
    [$actor, $company, $product] = r18UsageContext();
    $definition = r18UsageDefinition($company, $actor, 'material', AttributeScope::Product);
    app(SyncProductAttributeValuesAction::class)->execute($actor, $company, $product, [$definition->uuid => 'Steel']);

    $foreign = Company::factory()->create();
    $foreignDefinition = r18UsageDefinition($foreign, $actor, 'foreign_material', AttributeScope::Product);

    $viewer = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->create(['user_id' => $viewer, 'company_id' => $company, 'role' => CompanyRole::Viewer]);

    $this->actingAs($viewer)->get(route('catalog.attributes.usage', $definition->uuid))
        ->assertOk()
        ->assertSee('Usage Product');

    $this->get(route('catalog.attributes.usage', $foreignDefinition->uuid))->assertNotFound();
});
