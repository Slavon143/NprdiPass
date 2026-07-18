<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Enums\AuditEvent;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Models\AuditLog;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{User, Company, CompanyMembership} */
function bulkContext(CompanyRole $role = CompanyRole::Owner): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->create([
        'user_id' => $actor,
        'company_id' => $company,
        'role' => $role,
    ]);
    test()->actingAs($actor);
    app(CurrentCompany::class)->set($company);

    return [$actor, $company, $membership];
}

function bulkProduct(User $actor, Company $company, string $name = 'Bulk Product'): Product
{
    $slug = str($name)->slug()->toString();
    $catSlug = $slug.'-cat-'.bin2hex(random_bytes(4));

    $category = new Category;
    $category->forceFill([
        'company_id' => $company->id, 'parent_id' => null, 'depth' => 0,
        'name' => $name.' Category', 'slug' => $catSlug, 'slug_normalized' => $catSlug,
        'description' => null, 'sort_order' => 10, 'status' => CategoryStatus::Active,
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ])->save();

    return app(CreateProductAction::class)->execute($actor, $company, [
        'name' => $name,
        'slug' => $slug.'-'.bin2hex(random_bytes(4)),
        'short_description' => 'Bulk test product',
        'description' => null,
        'brand' => null,
        'manufacturer' => null,
    ], $category->uuid);
}

test('authorized user can bulk archive products', function () {
    [$actor, $company] = bulkContext();
    $p1 = bulkProduct($actor, $company, 'Product Alpha');
    $p2 = bulkProduct($actor, $company, 'Product Beta');

    $response = $this->delete(route('catalog.products.bulk-destroy'), [
        'products' => [$p1->uuid, $p2->uuid],
    ]);

    $response->assertRedirect(route('catalog.products.index'));
    $response->assertSessionHas('success');

    expect($p1->fresh()?->status)->toBe(ProductStatus::Archived)
        ->and($p2->fresh()?->status)->toBe(ProductStatus::Archived)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductArchived->value)->count())->toBe(2);
});

test('bulk archive skips already archived products', function () {
    [$actor, $company] = bulkContext();
    $p1 = bulkProduct($actor, $company, 'Active Product');
    $p2 = bulkProduct($actor, $company, 'Already Archived');

    $p2->forceFill(['status' => ProductStatus::Archived])->save();

    $response = $this->delete(route('catalog.products.bulk-destroy'), [
        'products' => [$p1->uuid, $p2->uuid],
    ]);

    $response->assertRedirect();

    expect($p1->fresh()?->status)->toBe(ProductStatus::Archived)
        ->and($p2->fresh()?->status)->toBe(ProductStatus::Archived)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductArchived->value)->count())->toBe(1);
});

test('bulk archive does not affect foreign company products', function () {
    [$actor, $company] = bulkContext();
    $owned = bulkProduct($actor, $company, 'Owned Product');

    $foreignCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $foreignCompany, 'user_id' => $actor]);
    app(CurrentCompany::class)->set($foreignCompany);
    $foreign = bulkProduct($actor, $foreignCompany, 'Foreign Product');
    app(CurrentCompany::class)->set($company);

    $response = $this->delete(route('catalog.products.bulk-destroy'), [
        'products' => [$owned->uuid, $foreign->uuid],
    ]);

    $response->assertRedirect();

    expect($owned->fresh()?->status)->toBe(ProductStatus::Archived)
        ->and($foreign->fresh()?->status)->toBe(ProductStatus::Draft);
});

test('bulk archive fails with empty products array', function () {
    bulkContext();

    $response = $this->delete(route('catalog.products.bulk-destroy'), [
        'products' => [],
    ]);

    $response->assertSessionHasErrors('products');
});

test('bulk archive fails with invalid UUID', function () {
    bulkContext();

    $response = $this->delete(route('catalog.products.bulk-destroy'), [
        'products' => ['not-a-valid-uuid'],
    ]);

    $response->assertSessionHasErrors('products.0');
});

test('bulk archive fails with exceeding limit', function () {
    bulkContext();

    $uuids = array_map(fn ($i) => fake()->uuid(), range(1, 101));

    $response = $this->delete(route('catalog.products.bulk-destroy'), [
        'products' => $uuids,
    ]);

    $response->assertSessionHasErrors('products');
});

test('bulk archive rejects duplicate UUIDs', function () {
    [$actor, $company] = bulkContext();
    $product = bulkProduct($actor, $company, 'Dedup Product');

    $response = $this->delete(route('catalog.products.bulk-destroy'), [
        'products' => [$product->uuid, $product->uuid],
    ]);

    $response->assertSessionHasErrors('products.*');

    expect($product->fresh()?->status)->toBe(ProductStatus::Draft)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductArchived->value)->count())->toBe(0);
});

test('user without catalog archive permission gets 403 on bulk archive', function () {
    [$owner, $company] = bulkContext(CompanyRole::Owner);
    $product = bulkProduct($owner, $company, 'Protected Product');

    $viewer = User::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $viewer,
        'company_id' => $company,
        'role' => CompanyRole::Viewer,
    ]);
    $this->actingAs($viewer);
    app(CurrentCompany::class)->set($company);

    $response = $this->delete(route('catalog.products.bulk-destroy'), [
        'products' => [$product->uuid],
    ]);

    $response->assertForbidden();
    expect($product->fresh()?->status)->toBe(ProductStatus::Draft);
});

test('unauthenticated request is redirected from bulk archive', function () {
    $response = $this->delete(route('catalog.products.bulk-destroy'), [
        'products' => ['00000000-0000-0000-0000-000000000001'],
    ]);

    $response->assertRedirect(route('login'));
});

test('bulk archive operation handles nonexistent UUIDs safely', function () {
    [$actor, $company] = bulkContext();
    $p1 = bulkProduct($actor, $company, 'Transaction Alpha');
    $p2 = bulkProduct($actor, $company, 'Transaction Beta');

    $fakeUuid = '00000000-0000-0000-0000-000000000000';
    $response = $this->delete(route('catalog.products.bulk-destroy'), [
        'products' => [$p1->uuid, $fakeUuid, $p2->uuid],
    ]);

    $response->assertRedirect();

    expect($p1->fresh()?->status)->toBe(ProductStatus::Archived)
        ->and($p2->fresh()?->status)->toBe(ProductStatus::Archived)
        ->and(AuditLog::query()->where('event', AuditEvent::CatalogProductArchived->value)->count())->toBe(2);
});

test('bulk archive preserves filters in redirect', function () {
    [$actor, $company] = bulkContext();
    $product = bulkProduct($actor, $company, 'Filtered Product');

    $response = $this->delete(route('catalog.products.bulk-destroy', [
        'q' => 'Filtered',
        'sort' => 'name',
        'direction' => 'asc',
        'page' => 3,
    ]), [
        'products' => [$product->uuid],
    ]);

    $response->assertRedirect();

    $target = $response->getTargetUrl();
    expect($target)->toContain('q=Filtered')
        ->and($target)->toContain('sort=name')
        ->and($target)->toContain('direction=asc')
        ->and($target)->toContain('page=3');
});

test('bulk archive preserves product filter statuses on redirect', function () {
    [$actor, $company] = bulkContext();
    $product = bulkProduct($actor, $company, 'Status Filter Product');

    $response = $this->delete(route('catalog.products.bulk-destroy', [
        'product_statuses' => ['draft', 'active'],
        'category_uuids' => ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'],
    ]), [
        'products' => [$product->uuid],
    ]);

    $response->assertRedirect();
    $target = $response->getTargetUrl();
    expect($target)->toContain('product_statuses%5B0%5D=draft')
        ->and($target)->toContain('product_statuses%5B1%5D=active');
});
