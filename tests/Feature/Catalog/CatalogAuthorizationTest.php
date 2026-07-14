<?php

use App\Authorization\CompanyAuthorizer;
use App\Authorization\CompanyPermissionMatrix;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeOptionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVariantStatus;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\AttributeOption;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Policies\Catalog\AttributeDefinitionPolicy;
use App\Policies\Catalog\AttributeOptionPolicy;
use App\Policies\Catalog\CategoryPolicy;
use App\Policies\Catalog\ProductMediaPolicy;
use App\Policies\Catalog\ProductPolicy;
use App\Policies\Catalog\ProductVariantPolicy;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function r13AuthorizationMembership(User $user, Company $company, CompanyRole $role): CompanyMembership
{
    return CompanyMembership::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'role' => $role,
    ]);
}

/** @return array{Product, ProductVariant, Category, AttributeDefinition, AttributeOption, ProductMedia} */
function r13AuthorizationEntities(Company $company, User $actor, string $suffix): array
{
    $product = new Product;
    $product->forceFill([
        'company_id' => $company->id,
        'name' => "Auth {$suffix}",
        'slug' => "auth-{$suffix}",
        'slug_normalized' => "auth-{$suffix}",
        'status' => ProductStatus::Draft,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();
    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'name' => 'Default',
        'status' => ProductVariantStatus::Draft,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();
    $product->forceFill(['default_variant_id' => $variant->id])->save();

    $category = new Category;
    $category->forceFill([
        'company_id' => $company->id,
        'name' => "Auth {$suffix}",
        'slug' => "auth-{$suffix}",
        'slug_normalized' => "auth-{$suffix}",
        'status' => CategoryStatus::Active,
    ])->save();
    $definition = new AttributeDefinition;
    $definition->forceFill([
        'company_id' => $company->id,
        'name' => "Auth {$suffix}",
        'code' => "auth_{$suffix}",
        'type' => AttributeDataType::Select,
        'scope' => AttributeScope::Both,
        'status' => AttributeDefinitionStatus::Active,
    ])->save();
    $option = new AttributeOption;
    $option->forceFill([
        'company_id' => $company->id,
        'attribute_definition_id' => $definition->id,
        'label' => 'Allowed',
        'code' => 'allowed',
        'status' => AttributeOptionStatus::Active,
    ])->save();
    $media = new ProductMedia;
    $media->forceFill([
        'company_id' => $company->id,
        'product_id' => $product->id,
        'original_filename' => 'auth.jpg',
        'storage_path' => "catalog/auth-{$suffix}.jpg",
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'checksum_sha256' => str_repeat('c', 64),
        'uploaded_by' => $actor->id,
    ])->save();

    return [$product->refresh(), $variant, $category, $definition, $option, $media];
}

$catalogMatrixCases = [];
$catalogPermissions = [
    CompanyPermission::CatalogView,
    CompanyPermission::CatalogCreate,
    CompanyPermission::CatalogUpdate,
    CompanyPermission::CatalogArchive,
    CompanyPermission::CatalogPublish,
    CompanyPermission::CatalogManageCategories,
    CompanyPermission::CatalogManageAttributes,
    CompanyPermission::CatalogManageMedia,
];

foreach (CompanyRole::cases() as $role) {
    foreach ($catalogPermissions as $permission) {
        $expected = match ($role) {
            CompanyRole::Owner, CompanyRole::Admin => true,
            CompanyRole::Editor => in_array($permission, [
                CompanyPermission::CatalogView,
                CompanyPermission::CatalogCreate,
                CompanyPermission::CatalogUpdate,
                CompanyPermission::CatalogManageMedia,
            ], true),
            CompanyRole::Viewer => $permission === CompanyPermission::CatalogView,
        };
        $catalogMatrixCases["{$role->value} / {$permission->value}"] = [$role, $permission, $expected];
    }
}

test('catalog permission matrix covers every role and catalog permission', function (
    CompanyRole $role,
    CompanyPermission $permission,
    bool $expected,
) {
    expect(app(CompanyPermissionMatrix::class)->allows($role, $permission))->toBe($expected);
})->with($catalogMatrixCases);

test('catalog authorizer and gates require active user membership current company and company', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $actor = User::factory()->create();
    $membership = r13AuthorizationMembership($actor, $company, CompanyRole::Owner);
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    expect(app(CompanyAuthorizer::class)->allows($actor, $company, CompanyPermission::CatalogCreate))->toBeTrue()
        ->and(Gate::forUser($actor)->allows(CompanyPermission::CatalogCreate->value, $company))->toBeTrue();

    app(CurrentCompany::class)->set($other);
    expect(app(CompanyAuthorizer::class)->allows($actor, $company, CompanyPermission::CatalogCreate))->toBeFalse();

    app(CurrentCompany::class)->set($company);
    $membership->delete();
    expect(app(CompanyAuthorizer::class)->allows($actor, $company, CompanyPermission::CatalogCreate))->toBeFalse();

    $membership = r13AuthorizationMembership($actor, $company, CompanyRole::Owner);
    $actor->forceFill(['status' => UserStatus::Suspended])->save();
    expect(app(CompanyAuthorizer::class)->allows($actor->refresh(), $company, CompanyPermission::CatalogCreate))->toBeFalse();

    $actor->forceFill(['status' => UserStatus::Active])->save();
    $company->forceFill(['status' => CompanyStatus::Suspended])->save();
    expect(Gate::forUser($actor->refresh())->allows(CompanyPermission::CatalogCreate->value, $company->refresh()))->toBeFalse();

    $membership->delete();
});

test('all catalog policies allow an authorized tenant and reject denied or cross-tenant access', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    r13AuthorizationMembership($owner, $companyA, CompanyRole::Owner);
    r13AuthorizationMembership($owner, $companyB, CompanyRole::Owner);
    r13AuthorizationMembership($viewer, $companyA, CompanyRole::Viewer);
    [$product, $variant, $category, $definition, $option, $media] = r13AuthorizationEntities($companyA, $owner, 'a');

    $checks = [
        [app(ProductPolicy::class), 'update', $product],
        [app(ProductVariantPolicy::class), 'update', $variant],
        [app(CategoryPolicy::class), 'update', $category],
        [app(AttributeDefinitionPolicy::class), 'update', $definition],
        [app(AttributeOptionPolicy::class), 'update', $option],
        [app(ProductMediaPolicy::class), 'update', $media],
    ];

    $this->actingAs($owner);
    app(CurrentCompany::class)->set($companyA);
    foreach ($checks as [$policy, $method, $model]) {
        expect($policy->{$method}($owner, $model))->toBeTrue();
    }
    expect(app(ProductVariantPolicy::class)->setDefault($owner, $variant))->toBeTrue();

    $this->actingAs($viewer);
    app(CurrentCompany::class)->set($companyA);
    foreach ($checks as [$policy, $method, $model]) {
        expect($policy->{$method}($viewer, $model))->toBeFalse();
    }
    expect(app(ProductPolicy::class)->view($viewer, $product))->toBeTrue()
        ->and(app(ProductVariantPolicy::class)->view($viewer, $variant))->toBeTrue()
        ->and(app(CategoryPolicy::class)->view($viewer, $category))->toBeTrue()
        ->and(app(AttributeDefinitionPolicy::class)->view($viewer, $definition))->toBeTrue()
        ->and(app(AttributeOptionPolicy::class)->view($viewer, $option))->toBeTrue()
        ->and(app(ProductMediaPolicy::class)->view($viewer, $media))->toBeTrue();

    $this->actingAs($owner);
    app(CurrentCompany::class)->set($companyB);
    foreach ($checks as [$policy, $method, $model]) {
        expect($policy->{$method}($owner, $model))->toBeFalse();
    }

    app(CurrentCompany::class)->clear();
    foreach ($checks as [$policy, $method, $model]) {
        expect($policy->{$method}($owner, $model))->toBeFalse();
    }

    CompanyMembership::query()
        ->where('user_id', $owner->id)
        ->where('company_id', $companyA->id)
        ->delete();
    app(CurrentCompany::class)->set($companyA);
    foreach ($checks as [$policy, $method, $model]) {
        expect($policy->{$method}($owner, $model))->toBeFalse();
    }
});

test('catalog policies reject inactive companies and stale in-memory tenant changes', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $actor = User::factory()->create();
    r13AuthorizationMembership($actor, $company, CompanyRole::Owner);
    r13AuthorizationMembership($actor, $other, CompanyRole::Owner);
    [$product] = r13AuthorizationEntities($company, $actor, 'stale');
    $this->actingAs($actor);
    app(CurrentCompany::class)->set($other);

    $product->forceFill(['company_id' => $other->id]);
    expect(app(ProductPolicy::class)->update($actor, $product))->toBeFalse();

    app(CurrentCompany::class)->set($company);
    $company->forceFill(['status' => CompanyStatus::Suspended])->save();
    expect(app(ProductPolicy::class)->update($actor, $product->fresh()))->toBeFalse();
});

test('catalog policies are explicitly registered', function () {
    expect(Gate::getPolicyFor(Product::class))->toBeInstanceOf(ProductPolicy::class)
        ->and(Gate::getPolicyFor(ProductVariant::class))->toBeInstanceOf(ProductVariantPolicy::class)
        ->and(Gate::getPolicyFor(Category::class))->toBeInstanceOf(CategoryPolicy::class)
        ->and(Gate::getPolicyFor(AttributeDefinition::class))->toBeInstanceOf(AttributeDefinitionPolicy::class)
        ->and(Gate::getPolicyFor(AttributeOption::class))->toBeInstanceOf(AttributeOptionPolicy::class)
        ->and(Gate::getPolicyFor(ProductMedia::class))->toBeInstanceOf(ProductMediaPolicy::class);
});
