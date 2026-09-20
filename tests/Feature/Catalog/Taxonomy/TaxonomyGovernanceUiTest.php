<?php

use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\CategoryTemplateAttributeRequirement;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\Taxonomy\CategoryTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('owner can manage category template and product shows needs review workflow', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $owner, 'company_id' => $company, 'role' => CompanyRole::Owner]);
    $category = new Category;
    $category->forceFill([
        'company_id' => $company->getKey(),
        'depth' => 0,
        'name' => 'Apparel',
        'slug' => 'apparel',
        'slug_normalized' => 'apparel',
        'status' => CategoryStatus::Active,
        'created_by' => $owner->getKey(),
        'updated_by' => $owner->getKey(),
    ])->save();
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $company->getKey(),
        'name' => 'Material',
        'code' => 'material',
        'type' => AttributeDataType::Text,
        'scope' => AttributeScope::Product,
        'required' => false,
        'filterable' => false,
        'searchable' => false,
        'status' => AttributeDefinitionStatus::Active,
        'created_by' => $owner->getKey(),
        'updated_by' => $owner->getKey(),
    ])->save();
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->getKey(),
        'primary_category_id' => $category->getKey(),
        'name' => 'Jacket',
        'slug' => 'jacket',
        'slug_normalized' => 'jacket',
        'status' => ProductStatus::Draft,
        'created_by' => $owner->getKey(),
        'updated_by' => $owner->getKey(),
    ])->save();

    $templates = app(CategoryTemplateService::class);
    $template = $templates->createDraft($category, $owner, 'Apparel standard');
    $templates->assignAttribute($template, $definition, CategoryTemplateAttributeRequirement::Required);
    $templates->activate($template, $owner);

    $this->actingAs($owner)
        ->get(route('catalog.categories.template.show', $category->uuid))
        ->assertOk()
        ->assertSee('Taxonomy template')
        ->assertSee('Apparel standard')
        ->assertSee('Version history');

    $this->get(route('catalog.products.show', $product->uuid))
        ->assertOk()
        ->assertSee('Category taxonomy')
        ->assertSee('Needs review')
        ->assertSee('Required before acknowledgement')
        ->assertSee('Material')
        ->assertDontSee('Acknowledge review');
});

test('viewer cannot open category template governance', function () {
    $viewer = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $viewer, 'company_id' => $company, 'role' => CompanyRole::Viewer]);
    $category = new Category;
    $category->forceFill([
        'company_id' => $company->getKey(),
        'depth' => 0,
        'name' => 'Protected',
        'slug' => 'protected',
        'slug_normalized' => 'protected',
        'status' => CategoryStatus::Active,
        'created_by' => $viewer->getKey(),
        'updated_by' => $viewer->getKey(),
    ])->save();

    $this->actingAs($viewer)->get(route('catalog.categories.template.show', $category->uuid))->assertForbidden();
});
