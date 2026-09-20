<?php

use App\Enums\ApiTokenAbility;
use App\Enums\AuditEvent;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionApprovalStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductAttributeValue;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function taxonomyApiCategory(Company $company, User $actor, string $suffix): Category
{
    $category = new Category;
    $category->forceFill([
        'company_id' => $company->getKey(),
        'depth' => 0,
        'name' => "Taxonomy {$suffix}",
        'slug' => "taxonomy-{$suffix}",
        'slug_normalized' => "taxonomy-{$suffix}",
        'status' => CategoryStatus::Active,
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    return $category->refresh();
}

function taxonomyApiDefinition(Company $company, User $actor, string $code): AttributeDefinition
{
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $company->getKey(),
        'name' => ucfirst($code),
        'code' => $code,
        'type' => AttributeDataType::Text,
        'scope' => AttributeScope::Product,
        'required' => false,
        'filterable' => false,
        'searchable' => false,
        'status' => AttributeDefinitionStatus::Active,
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    return $definition->refresh();
}

function taxonomyApiProduct(Company $company, User $actor, Category $category): Product
{
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'primary_category_id' => $category->getKey(),
        'name' => 'Governed product',
        'slug' => 'governed-product',
        'slug_normalized' => 'governed-product',
        'status' => ProductStatus::Draft,
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    return $product->refresh();
}

test('category template API versions activates and exposes product needs review', function () {
    [$user, $company] = apiCatalogContext();
    $category = taxonomyApiCategory($company, $user, 'api');
    $attribute = taxonomyApiDefinition($company, $user, 'material');
    $product = taxonomyApiProduct($company, $user, $category);
    $abilities = [
        ApiTokenAbility::CatalogRead->value,
        ApiTokenAbility::CatalogWrite->value,
        ApiTokenAbility::CatalogLifecycle->value,
    ];

    $created = apiPost($user, $company, $abilities, "categories/{$category->uuid}/templates", [
        'name' => 'Textiles standard',
    ])->assertCreated();
    $templateUuid = $created->json('data.uuid');

    apiPut($user, $company, $abilities, "categories/{$category->uuid}/templates/{$templateUuid}", [
        'name' => 'Textiles standard',
        'attributes' => [[
            'attribute_uuid' => $attribute->uuid,
            'requirement' => 'required',
            'sort_order' => 10,
        ]],
        'dpp_defaults' => ['identity' => ['public_name' => 'Category default']],
    ])->assertOk()->assertJsonPath('data.attributes.0.requirement', 'required');

    apiPost($user, $company, $abilities, "categories/{$category->uuid}/templates/{$templateUuid}/activate")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    apiGet($user, $company, $abilities, "products/{$product->uuid}/taxonomy")
        ->assertOk()
        ->assertJsonPath('data.needs_review', true)
        ->assertJsonPath('data.missing_required.0.code', 'material')
        ->assertJsonPath('data.effective_readiness_profile.code', 'nordipass-pilot');

    apiPost($user, $company, $abilities, "products/{$product->uuid}/taxonomy/acknowledge")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_error');

    $value = new ProductAttributeValue;
    $value->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'attribute_definition_id' => $attribute->getKey(),
        'value_text' => 'Organic cotton',
    ])->save();

    apiPost($user, $company, $abilities, "products/{$product->uuid}/taxonomy/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.needs_review', false);

    $this->assertDatabaseHas('activity_log', ['event' => AuditEvent::CatalogCategoryTemplateActivated->value]);
    $this->assertDatabaseHas('activity_log', ['event' => AuditEvent::CatalogProductTaxonomyAcknowledged->value]);
});

test('taxonomy API rejects category templates from another tenant', function () {
    [$user, $company] = apiCatalogContext();
    [, $foreignCompany] = apiCatalogContext();
    $foreignCategory = taxonomyApiCategory($foreignCompany, $user, 'foreign');

    apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "categories/{$foreignCategory->uuid}/templates")
        ->assertNotFound();
});

test('taxonomy lifecycle API approves options and requires explicit replacements', function () {
    [$user, $company] = apiCatalogContext();
    $old = taxonomyApiDefinition($company, $user, 'legacy_material');
    $replacementDefinition = taxonomyApiDefinition($company, $user, 'material_v2');
    $old->forceFill(['type' => AttributeDataType::Select, 'vocabulary_controlled' => true])->save();
    $replacementDefinition->forceFill(['type' => AttributeDataType::Select])->save();
    $pending = new AttributeOption;
    $pending->forceFill([
        'company_id' => $company->getKey(),
        'attribute_definition_id' => $old->getKey(),
        'label' => 'Legacy cotton',
        'code' => 'legacy_cotton',
        'status' => AttributeOptionStatus::Active,
        'approval_status' => AttributeOptionApprovalStatus::Pending,
    ])->save();
    $replacement = new AttributeOption;
    $replacement->forceFill([
        'company_id' => $company->getKey(),
        'attribute_definition_id' => $old->getKey(),
        'label' => 'Cotton',
        'code' => 'cotton',
        'status' => AttributeOptionStatus::Active,
        'approval_status' => AttributeOptionApprovalStatus::Approved,
    ])->save();
    $abilities = [ApiTokenAbility::CatalogRead->value, ApiTokenAbility::CatalogLifecycle->value];

    apiPost($user, $company, $abilities, "attributes/{$old->uuid}/options/{$pending->getKey()}/approve")
        ->assertOk()
        ->assertJsonPath('data.approval_status', 'approved');
    apiPost($user, $company, $abilities, "attributes/{$old->uuid}/options/{$pending->getKey()}/deprecate", [
        'replacement_option_id' => $replacement->getKey(),
    ])->assertOk()->assertJsonPath('data.status', 'deprecated');
    apiPost($user, $company, $abilities, "attributes/{$old->uuid}/deprecate", [
        'replacement_uuid' => $replacementDefinition->uuid,
    ])->assertOk()->assertJsonPath('data.status', 'deprecated');

    $this->assertDatabaseHas('activity_log', ['event' => AuditEvent::CatalogAttributeOptionApproved->value]);
    $this->assertDatabaseHas('activity_log', ['event' => AuditEvent::CatalogAttributeOptionDeprecated->value]);
    $this->assertDatabaseHas('activity_log', ['event' => AuditEvent::CatalogAttributeDeprecated->value]);
});
