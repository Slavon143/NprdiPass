<?php

use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Catalog\ProductVariant;
use App\Models\Catalog\VariantAttributeValue;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{User, Company, CompanyMembership} */
function attrDelContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->create([
        'user_id' => $actor, 'company_id' => $company, 'role' => $role,
    ]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company, $membership];
}

function attrDelDefinition(User $actor, Company $company, string $name = 'Delete Attribute', string $code = 'del_attr'): AttributeDefinition
{
    $code = $code.'_'.bin2hex(random_bytes(4));
    $def = new AttributeDefinition;
    $def->forceFill([
        'company_id' => $company->id, 'name' => $name, 'code' => $code,
        'description' => null, 'type' => AttributeDataType::Text, 'scope' => AttributeScope::Product,
        'unit' => null, 'required' => false, 'filterable' => false, 'searchable' => false,
        'validation_rules' => null, 'sort_order' => 10,
        'status' => AttributeDefinitionStatus::Active,
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ])->save();

    return $def->refresh();
}

function attrDelProduct(Company $company, string $name): Product
{
    $slug = str($name)->slug()->toString().'-'.bin2hex(random_bytes(4));
    $p = new Product;
    $p->forceFill([
        'company_id' => $company->id, 'name' => $name, 'slug' => $slug,
        'slug_normalized' => $slug, 'status' => ProductStatus::Draft,
    ])->save();

    return $p->refresh();
}

function attrDelVariant(Company $company, Product $product, string $name): ProductVariant
{
    $v = new ProductVariant;
    $v->forceFill([
        'company_id' => $company->id, 'product_id' => $product->id,
        'name' => $name, 'sort_order' => 0, 'status' => ProductVariantStatus::Draft,
    ])->save();

    return $v->refresh();
}

test('authorized user can bulk delete unused attributes', function () {
    [$actor, $company] = attrDelContext();
    $a1 = attrDelDefinition($actor, $company, 'Attr Alpha', 'alpha');
    $a2 = attrDelDefinition($actor, $company, 'Attr Beta', 'beta');

    $this->delete(route('catalog.attributes.bulk-destroy'), ['attributes' => [$a1->uuid, $a2->uuid]])
        ->assertRedirect(route('catalog.attributes.index'))
        ->assertSessionHas('success');

    expect(AttributeDefinition::find($a1->id))->toBeNull()
        ->and(AttributeDefinition::find($a2->id))->toBeNull();
});

test('bulk delete is atomic: clean attribute is not deleted when another attribute is blocked', function () {
    [$actor, $company] = attrDelContext();
    $clean = attrDelDefinition($actor, $company, 'Clean Attr', 'clean');
    $used = attrDelDefinition($actor, $company, 'Used Attr', 'used');
    $p = attrDelProduct($company, 'Test Product');
    $val = new ProductAttributeValue;
    $val->forceFill(['company_id' => $company->id, 'product_id' => $p->id, 'attribute_definition_id' => $used->id, 'value_text' => 'x'])->save();

    $this->delete(route('catalog.attributes.bulk-destroy'), ['attributes' => [$clean->uuid, $used->uuid]])
        ->assertSessionHas('error');

    expect(AttributeDefinition::find($clean->id))->not->toBeNull()
        ->and(AttributeDefinition::find($used->id))->not->toBeNull();
});

test('single delete works for unused attribute', function () {
    [$actor, $company] = attrDelContext();
    $a = attrDelDefinition($actor, $company, 'Solo', 'solo');
    $this->delete(route('catalog.attributes.destroy', $a->uuid))
        ->assertSessionHas('success');
    expect(AttributeDefinition::find($a->id))->toBeNull();
});

test('single delete blocks attribute with product values', function () {
    [$actor, $company] = attrDelContext();
    $a = attrDelDefinition($actor, $company, 'Used', 'used');
    $p = attrDelProduct($company, 'P');
    $val = new ProductAttributeValue;
    $val->forceFill(['company_id' => $company->id, 'product_id' => $p->id, 'attribute_definition_id' => $a->id, 'value_text' => 'x'])->save();

    $this->delete(route('catalog.attributes.destroy', $a->uuid))->assertSessionHas('error');
    expect(AttributeDefinition::find($a->id))->not->toBeNull();
});

test('single delete blocks attribute used by archived products to preserve restore integrity', function () {
    [$actor, $company] = attrDelContext();
    $a = attrDelDefinition($actor, $company, 'Archived Product Used', 'arch_prod');
    $p = attrDelProduct($company, 'Archived P');
    $p->forceFill(['status' => ProductStatus::Archived])->save();

    $val = new ProductAttributeValue;
    $val->forceFill(['company_id' => $company->id, 'product_id' => $p->id, 'attribute_definition_id' => $a->id, 'value_text' => 'x'])->save();

    $this->delete(route('catalog.attributes.destroy', $a->uuid))->assertSessionHas('error');

    expect(AttributeDefinition::find($a->id))->not->toBeNull()
        ->and(ProductAttributeValue::query()->whereKey($val->id)->exists())->toBeTrue();
});

test('bulk delete blocks attribute with variant values', function () {
    [$actor, $company] = attrDelContext();
    $a = attrDelDefinition($actor, $company, 'Var Attr', 'var');
    $p = attrDelProduct($company, 'VP');
    $v = attrDelVariant($company, $p, 'V1');
    $val = new VariantAttributeValue;
    $val->forceFill(['company_id' => $company->id, 'product_variant_id' => $v->id, 'attribute_definition_id' => $a->id, 'value_text' => 'x'])->save();

    $this->delete(route('catalog.attributes.bulk-destroy'), ['attributes' => [$a->uuid]])->assertSessionHas('error');
    expect(AttributeDefinition::find($a->id))->not->toBeNull();
});

test('bulk delete blocks attribute used by archived product variants to preserve restore integrity', function () {
    [$actor, $company] = attrDelContext();
    $a = attrDelDefinition($actor, $company, 'Archived Variant Attr', 'arch_var');
    $p = attrDelProduct($company, 'Archived VP');
    $p->forceFill(['status' => ProductStatus::Archived])->save();
    $v = attrDelVariant($company, $p, 'V1');

    $val = new VariantAttributeValue;
    $val->forceFill(['company_id' => $company->id, 'product_variant_id' => $v->id, 'attribute_definition_id' => $a->id, 'value_text' => 'x'])->save();

    $this->delete(route('catalog.attributes.bulk-destroy'), ['attributes' => [$a->uuid]])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(AttributeDefinition::find($a->id))->not->toBeNull()
        ->and(VariantAttributeValue::query()->whereKey($val->id)->exists())->toBeTrue();
});

test('validation rejects empty and invalid', function () {
    attrDelContext();
    $this->delete(route('catalog.attributes.bulk-destroy'), ['attributes' => []])->assertSessionHasErrors('attributes');
    $this->delete(route('catalog.attributes.bulk-destroy'), ['attributes' => ['bad']])->assertSessionHasErrors('attributes.0');
});

test('user without permission gets 403', function () {
    [$owner, $company] = attrDelContext(CompanyRole::Owner);
    $a = attrDelDefinition($owner, $company, 'Protected', 'prot');
    $viewer = User::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $viewer, 'company_id' => $company, 'role' => CompanyRole::Viewer]);
    $this->actingAs($viewer);
    app(CurrentCompany::class)->set($company);

    $this->delete(route('catalog.attributes.destroy', $a->uuid))->assertForbidden();
    $this->delete(route('catalog.attributes.bulk-destroy'), ['attributes' => [$a->uuid]])->assertForbidden();
    expect(AttributeDefinition::find($a->id))->not->toBeNull();
});

test('foreign company attributes not deleted', function () {
    [$actor, $company] = attrDelContext();
    $owned = attrDelDefinition($actor, $company, 'Owned', 'owned');
    $fc = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $fc, 'user_id' => $actor]);
    app(CurrentCompany::class)->set($fc);
    $foreign = attrDelDefinition($actor, $fc, 'Foreign', 'foreign');
    app(CurrentCompany::class)->set($company);

    $this->delete(route('catalog.attributes.bulk-destroy'), ['attributes' => [$owned->uuid, $foreign->uuid]])
        ->assertSessionHas('error');
    expect(AttributeDefinition::find($owned->id))->not->toBeNull()
        ->and(AttributeDefinition::find($foreign->id))->not->toBeNull();
});

test('duplicate UUIDs rejected', function () {
    [$actor, $company] = attrDelContext();
    $a = attrDelDefinition($actor, $company, 'Dedup', 'dedup');
    $this->delete(route('catalog.attributes.bulk-destroy'), ['attributes' => [$a->uuid, $a->uuid]])
        ->assertSessionHasErrors('attributes.*');
    expect(AttributeDefinition::find($a->id))->not->toBeNull();
});

test('audit log created on delete', function () {
    [$actor, $company] = attrDelContext();
    $a = attrDelDefinition($actor, $company, 'Audit', 'audit');
    $before = AuditLog::count();
    $this->delete(route('catalog.attributes.destroy', $a->uuid));
    expect(AuditLog::count())->toBe($before + 1);
});
