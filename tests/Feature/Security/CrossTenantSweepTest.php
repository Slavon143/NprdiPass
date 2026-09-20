<?php

use App\Enums\ApiTokenAbility;
use App\Enums\Catalog\AttributeDataType;
use App\Enums\Catalog\AttributeDefinitionStatus;
use App\Enums\Catalog\AttributeScope;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyStatus;
use App\Enums\Jobs\JobStatusState;
use App\Enums\Notifications\NotificationType;
use App\Models\Catalog\AttributeDefinition;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Jobs\JobStatus;
use App\Models\Notifications\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Api/V1/Catalog/Helpers.php';

uses(RefreshDatabase::class);

/** @return array{User, Company} */
function r16TenantContext(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create(['status' => CompanyStatus::Active]);
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    return [$user, $company];
}

test('catalog resources are hidden across tenants for every lifecycle route', function () {
    [$ownerA, $companyA] = r16TenantContext();
    [$ownerB] = r16TenantContext();

    $product = new Product;
    $product->forceFill([
        'company_id' => $companyA->id,
        'name' => 'Tenant A Product',
        'slug' => 'tenant-a-product',
        'slug_normalized' => 'tenant-a-product',
        'status' => ProductStatus::Draft,
        'created_by' => $ownerA->id,
        'updated_by' => $ownerA->id,
    ])->save();
    $product->refresh();

    $category = new Category;
    $category->forceFill([
        'company_id' => $companyA->id,
        'parent_id' => null,
        'depth' => 0,
        'name' => 'Tenant A Category',
        'slug' => 'tenant-a-category',
        'slug_normalized' => 'tenant-a-category',
        'sort_order' => 10,
        'status' => CategoryStatus::Active,
        'created_by' => $ownerA->id,
        'updated_by' => $ownerA->id,
    ])->save();
    $category->refresh();

    $attribute = new AttributeDefinition;
    $attribute->forceFill([
        'company_id' => $companyA->id,
        'name' => 'Tenant A Attribute',
        'code' => 'tenant_a_attribute',
        'type' => AttributeDataType::Select,
        'scope' => AttributeScope::Both,
        'status' => AttributeDefinitionStatus::Active,
    ])->save();
    $attribute->refresh();

    $this->actingAs($ownerB)
        ->get(route('catalog.products.show', $product))->assertNotFound();

    $this->actingAs($ownerB)
        ->get(route('catalog.products.edit', $product))->assertNotFound();

    $this->actingAs($ownerB)
        ->patch(route('catalog.products.update', $product), ['name' => 'Hijacked'])->assertNotFound();

    $this->actingAs($ownerB)
        ->post(route('catalog.products.archive', $product))->assertNotFound();

    $this->actingAs($ownerB)
        ->get(route('catalog.categories.edit', $category))->assertNotFound();

    $this->actingAs($ownerB)
        ->patch(route('catalog.categories.update', $category), ['name' => 'Hijacked'])->assertNotFound();

    $this->actingAs($ownerB)
        ->get(route('catalog.attributes.show', $attribute))->assertNotFound();

    $this->actingAs($ownerB)
        ->get(route('catalog.attributes.edit', $attribute))->assertNotFound();

    expect($product->fresh()->name)->toBe('Tenant A Product');
});

test('job center and notification resources are hidden across tenants', function () {
    [$ownerA, $companyA] = r16TenantContext();
    [$ownerB] = r16TenantContext();

    $job = JobStatus::query()->create([
        'company_id' => $companyA->id,
        'user_id' => $ownerA->id,
        'name' => 'Tenant A Import',
        'type' => 'imports.apply',
        'queue' => 'imports',
        'status' => JobStatusState::Running,
        'idempotency_key' => 'tenant-a-import',
    ]);

    $notification = Notification::query()->create([
        'company_id' => $companyA->id,
        'user_id' => $ownerA->id,
        'type' => NotificationType::ImportCompleted,
        'preference_key' => 'imports',
        'title' => 'Tenant A import finished',
        'idempotency_key' => 'tenant-a-notification',
    ]);

    $this->actingAs($ownerB)
        ->get(route('job-center.show', $job))->assertNotFound();

    $this->actingAs($ownerB)
        ->post(route('notifications.read', $notification))->assertNotFound();

    $this->actingAs($ownerB)
        ->post(route('notifications.dismiss', $notification))->assertNotFound();
});

test('API tokens of one company cannot be revoked by another company', function () {
    [$ownerA, $companyA] = r16TenantContext();
    [$ownerB] = r16TenantContext();

    $token = issueCompanyApiToken($ownerA, $companyA, [ApiTokenAbility::CatalogRead->value]);

    $response = $this->actingAs($ownerB)
        ->delete(route('settings.api-tokens.destroy', ['token' => $token->accessToken->getKey()]));

    expect(in_array($response->status(), [403, 404], true))->toBeTrue()
        ->and($token->accessToken->fresh())->not->toBeNull();
});

test('API token of tenant A cannot read tenant B catalog resources', function () {
    [$ownerA, $companyA] = r16TenantContext();
    [$ownerB, $companyB] = r16TenantContext();

    $product = new Product;
    $product->forceFill([
        'company_id' => $companyA->id,
        'name' => 'Hidden API Product',
        'slug' => 'hidden-api-product',
        'slug_normalized' => 'hidden-api-product',
        'status' => ProductStatus::Draft,
        'created_by' => $ownerA->id,
        'updated_by' => $ownerA->id,
    ])->save();
    $product->refresh();

    apiGet($ownerB, $companyB, [ApiTokenAbility::CatalogRead->value], 'products/'.$product->uuid)
        ->assertNotFound()->assertJsonPath('error.code', 'resource_not_found');
});

test('company-scoped list endpoints never mix tenant rows', function () {
    [$ownerA, $companyA] = r16TenantContext();
    [$ownerB] = r16TenantContext();

    $product = new Product;
    $product->forceFill([
        'company_id' => $companyA->id,
        'name' => 'Only For A',
        'slug' => 'only-for-a',
        'slug_normalized' => 'only-for-a',
        'status' => ProductStatus::Draft,
        'created_by' => $ownerA->id,
        'updated_by' => $ownerA->id,
    ])->save();

    $this->actingAs($ownerB)
        ->get(route('catalog.products.index'))
        ->assertOk()
        ->assertDontSee('Only For A');

    $this->actingAs($ownerB)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertDontSee('Tenant A import finished');

    $this->actingAs($ownerB)
        ->get(route('job-center.index'))
        ->assertOk()
        ->assertDontSee('Tenant A Import');
});
