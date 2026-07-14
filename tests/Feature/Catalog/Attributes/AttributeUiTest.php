<?php

use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\CompanyRole;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** @return array{User, Company, Product} */
function r17UiContext(CompanyRole $role = CompanyRole::Owner): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $user, 'company_id' => $company, 'role' => $role]);
    $product = app(ProductAggregateCreator::class)->create($user, $company, [
        'name' => 'UI Product', 'slug' => 'ui-product', 'short_description' => null,
        'description' => null, 'brand' => null, 'manufacturer' => null,
    ], ['name' => 'Default', 'sku' => null, 'sku_normalized' => null, 'gtin' => null, 'mpn' => null, 'sort_order' => 0]);

    return [$user, $company, $product];
}

function r17UiDefinition(Company $company, User $actor, string $code, AttributeScope $scope, bool $required = false): AttributeDefinition
{
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $company->id, 'name' => str($code)->headline()->toString(), 'code' => $code,
        'type' => AttributeDataType::Text, 'scope' => $scope, 'required' => $required, 'filterable' => false,
        'searchable' => false, 'sort_order' => 10, 'status' => AttributeDefinitionStatus::Active,
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ])->save();

    return $definition->refresh();
}

test('attribute index and navigation are tenant scoped and viewer read only', function () {
    [$viewer, $company] = r17UiContext(CompanyRole::Viewer);
    r17UiDefinition($company, $viewer, 'visible_attribute', AttributeScope::Product);
    $foreign = Company::factory()->create();
    r17UiDefinition($foreign, $viewer, 'foreign_secret', AttributeScope::Product);

    $this->actingAs($viewer)->get(route('catalog.attributes.index'))
        ->assertOk()
        ->assertSee('Attributes')
        ->assertSee('Visible Attribute')
        ->assertDontSee('Foreign Secret')
        ->assertDontSee('Create attribute');
});

test('definition management pages render typed fields and options only for option types', function () {
    [$owner, $company] = r17UiContext();
    $text = r17UiDefinition($company, $owner, 'text_attribute', AttributeScope::Product);

    $this->actingAs($owner)->get(route('catalog.attributes.create'))
        ->assertOk()
        ->assertSee('Text min length')
        ->assertSee('Numeric minimum')
        ->assertSee('Minimum selections');
    $this->get(route('catalog.attributes.show', $text->uuid))
        ->assertOk()
        ->assertSee('This attribute type does not use predefined options.')
        ->assertDontSee('New option label');

    $this->post(route('catalog.attributes.store'), [
        'name' => 'Color', 'code' => 'Color', 'type' => 'select', 'scope' => 'variant', 'unit' => null,
        'required' => '0', 'filterable' => '1', 'searchable' => '0', 'sort_order' => 20, 'validation_rules' => [],
    ])->assertRedirect();
    $color = AttributeDefinition::query()->where('code', 'color')->sole();
    $this->get(route('catalog.attributes.show', $color->uuid))->assertOk()->assertSee('New option label');

    $color->forceFill(['required' => true])->save();
    $this->patch(route('catalog.attributes.update', $color->uuid), [
        'name' => $color->name,
        'code' => $color->code,
        'description' => null,
        'type' => 'select',
        'scope' => 'variant',
        'unit' => null,
        'required' => '0',
        'filterable' => '1',
        'searchable' => '0',
        'sort_order' => 20,
        'validation_rules' => [
            'min_length' => '', 'max_length' => '', 'min' => '', 'max' => '',
            'min_date' => '', 'max_date' => '', 'min_selections' => '', 'max_selections' => '',
        ],
    ])->assertRedirect(route('catalog.attributes.show', $color->uuid));
    expect($color->fresh()?->required)->toBeFalse()
        ->and($color->fresh()?->validation_rules)->toBeNull();
});

test('Product and Variant assignment pages expose only compatible scopes and show required missing state', function () {
    [$editor, $company, $product] = r17UiContext(CompanyRole::Editor);
    $productDefinition = r17UiDefinition($company, $editor, 'product_required', AttributeScope::Product, true);
    $variantDefinition = r17UiDefinition($company, $editor, 'variant_required', AttributeScope::Variant, true);
    $bothDefinition = r17UiDefinition($company, $editor, 'both_optional', AttributeScope::Both);
    $variant = $product->defaultVariant()->firstOrFail();

    $this->actingAs($editor)->get(route('catalog.products.attributes.edit', $product->uuid))
        ->assertOk()->assertSee($productDefinition->name)->assertSee($bothDefinition->name)->assertDontSee($variantDefinition->name);
    $this->get(route('catalog.products.variants.attributes.edit', [$product->uuid, $variant->uuid]))
        ->assertOk()->assertSee($variantDefinition->name)->assertSee($bothDefinition->name)->assertDontSee($productDefinition->name);
    $this->get(route('catalog.products.show', $product->uuid))
        ->assertOk()->assertSee('Product Attributes')->assertSee('Missing required value');
    $this->get(route('catalog.products.variants.show', [$product->uuid, $variant->uuid]))
        ->assertOk()->assertSee('Variant Attributes')->assertSee('Missing required value');

    $this->put(route('catalog.products.attributes.update', $product->uuid), [
        'attributes' => [$productDefinition->uuid => 'Assigned', $bothDefinition->uuid => 'Independent product'],
    ])->assertRedirect(route('catalog.products.show', $product->uuid));
    $this->put(route('catalog.products.variants.attributes.update', [$product->uuid, $variant->uuid]), [
        'attributes' => [$variantDefinition->uuid => 'Assigned', $bothDefinition->uuid => 'Independent variant'],
    ])->assertRedirect(route('catalog.products.variants.show', [$product->uuid, $variant->uuid]));
});

test('viewer cannot mutate values and wrong tenant or wrong Product UUIDs are concealed', function () {
    [$viewer, $company, $product] = r17UiContext(CompanyRole::Viewer);
    $definition = r17UiDefinition($company, $viewer, 'read_only', AttributeScope::Product);
    $variant = $product->defaultVariant()->firstOrFail();
    $otherCompany = Company::factory()->create();
    $foreign = r17UiDefinition($otherCompany, $viewer, 'foreign', AttributeScope::Product);

    $this->actingAs($viewer)->get(route('catalog.products.show', $product->uuid))
        ->assertOk()->assertDontSee('Edit attributes');
    $this->put(route('catalog.products.attributes.update', $product->uuid), ['attributes' => [$definition->uuid => 'No']])->assertForbidden();
    $this->get(route('catalog.attributes.show', $foreign->uuid))->assertNotFound();

    $otherProduct = app(ProductAggregateCreator::class)->create($viewer, $company, [
        'name' => 'Other', 'slug' => 'other-ui', 'short_description' => null, 'description' => null, 'brand' => null, 'manufacturer' => null,
    ], ['name' => 'Default', 'sku' => null, 'sku_normalized' => null, 'gtin' => null, 'mpn' => null, 'sort_order' => 0]);
    $this->get(route('catalog.products.variants.attributes.edit', [$otherProduct->uuid, $variant->uuid]))->assertNotFound();
});

test('attribute routes require authentication and GET routes do not mutate state', function () {
    [$owner, $company] = r17UiContext();
    $definition = r17UiDefinition($company, $owner, 'stable', AttributeScope::Product);
    auth()->logout();
    $this->get(route('catalog.attributes.index'))->assertRedirect(route('login'));
    $this->actingAs($owner)->get(route('catalog.attributes.show', $definition->uuid))->assertOk();
    expect($definition->fresh()?->status)->toBe(AttributeDefinitionStatus::Active);
});

test('attribute index query count stays bounded as definition rows grow', function () {
    [$owner, $company] = r17UiContext();
    $this->actingAs($owner);
    r17UiDefinition($company, $owner, 'bounded_1', AttributeScope::Product);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('catalog.attributes.index'))->assertOk();
    $singleRowQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    foreach (range(2, 20) as $number) {
        r17UiDefinition($company, $owner, 'bounded_'.$number, AttributeScope::Product);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('catalog.attributes.index'))->assertOk();
    $twentyRowQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($twentyRowQueries)->toBeLessThanOrEqual(50)
        ->and($twentyRowQueries)->toBeLessThanOrEqual($singleRowQueries + 2);
});
