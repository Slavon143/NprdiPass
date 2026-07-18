<?php

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
function catDelContext(CompanyRole $role = CompanyRole::Owner): array
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

function catDelCategory(User $actor, Company $company, string $name = 'Delete Category', ?Category $parent = null): Category
{
    $slug = str($name)->slug()->toString().'-'.bin2hex(random_bytes(4));
    $cat = new Category;
    $cat->forceFill([
        'company_id' => $company->id, 'parent_id' => $parent?->getKey(),
        'depth' => $parent ? $parent->depth + 1 : 0,
        'name' => $name, 'slug' => $slug, 'slug_normalized' => $slug,
        'description' => null, 'sort_order' => 10,
        'status' => CategoryStatus::Active,
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ])->save();

    return $cat->refresh();
}

function catDelProduct(Company $company, string $name, int $primaryCategoryId, array $categoryIds = []): Product
{
    $slug = str($name)->slug()->toString().'-'.bin2hex(random_bytes(4));
    $p = new Product;
    $p->forceFill([
        'company_id' => $company->id, 'name' => $name, 'slug' => $slug,
        'slug_normalized' => $slug, 'primary_category_id' => $primaryCategoryId,
        'status' => ProductStatus::Draft,
    ])->save();
    $p->refresh();

    foreach ($categoryIds as $cid) {
        $p->categories()->attach($cid, ['company_id' => $company->id]);
    }

    return $p;
}

test('authorized user can bulk delete empty categories', function () {
    [$actor, $company] = catDelContext();
    $c1 = catDelCategory($actor, $company, 'Category Alpha');
    $c2 = catDelCategory($actor, $company, 'Category Beta');

    $response = $this->delete(route('catalog.categories.bulk-destroy'), [
        'categories' => [$c1->uuid, $c2->uuid],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(Category::withTrashed()->find($c1->id)?->trashed())->toBeTrue()
        ->and(Category::withTrashed()->find($c2->id)?->trashed())->toBeTrue();
});

test('bulk delete is atomic: clean category is not deleted when another category is blocked', function () {
    [$actor, $company] = catDelContext();
    $clean = catDelCategory($actor, $company, 'Clean Category');
    $parent = catDelCategory($actor, $company, 'Parent Category');
    catDelCategory($actor, $company, 'Child Category', $parent);

    $response = $this->delete(route('catalog.categories.bulk-destroy'), [
        'categories' => [$clean->uuid, $parent->uuid],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');

    expect(Category::find($clean->id))->not->toBeNull()
        ->and(Category::find($parent->id))->not->toBeNull();
});

test('bulk delete blocks category with primary products', function () {
    [$actor, $company] = catDelContext();
    $cat = catDelCategory($actor, $company, 'Primary Cat');
    catDelProduct($company, 'Primary Product', $cat->id);

    $response = $this->delete(route('catalog.categories.bulk-destroy'), [
        'categories' => [$cat->uuid],
    ]);

    $response->assertSessionHas('error');
    expect(Category::find($cat->id))->not->toBeNull();
});

test('bulk delete blocks category with pivot products', function () {
    [$actor, $company] = catDelContext();
    $cat = catDelCategory($actor, $company, 'Pivot Cat');
    catDelProduct($company, 'Test Product', $cat->id, [$cat->id]);

    $response = $this->delete(route('catalog.categories.bulk-destroy'), [
        'categories' => [$cat->uuid],
    ]);

    $response->assertSessionHas('error');
    expect(Category::find($cat->id))->not->toBeNull();
});

test('single delete works for empty category', function () {
    [$actor, $company] = catDelContext();
    $cat = catDelCategory($actor, $company, 'Solo Category');

    $response = $this->delete(route('catalog.categories.destroy', $cat->uuid));

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(Category::withTrashed()->find($cat->id)?->trashed())->toBeTrue();
});

test('single delete ignores archived primary products', function () {
    [$actor, $company] = catDelContext();
    $cat = catDelCategory($actor, $company, 'Archived Primary Cat');
    $product = catDelProduct($company, 'Archived Primary Product', $cat->id);
    $product->forceFill(['status' => ProductStatus::Archived])->save();

    $response = $this->delete(route('catalog.categories.destroy', $cat->uuid));

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(Category::withTrashed()->find($cat->id)?->trashed())->toBeTrue();
});

test('bulk delete ignores archived assigned products', function () {
    [$actor, $company] = catDelContext();
    $cat = catDelCategory($actor, $company, 'Archived Assigned Cat');
    $product = catDelProduct($company, 'Archived Assigned Product', $cat->id, [$cat->id]);
    $product->forceFill(['status' => ProductStatus::Archived])->save();

    $response = $this->delete(route('catalog.categories.bulk-destroy'), [
        'categories' => [$cat->uuid],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    expect(Category::withTrashed()->find($cat->id)?->trashed())->toBeTrue();
});

test('single delete blocks category with children', function () {
    [$actor, $company] = catDelContext();
    $parent = catDelCategory($actor, $company, 'Parent');
    catDelCategory($actor, $company, 'Child', $parent);

    $response = $this->delete(route('catalog.categories.destroy', $parent->uuid));

    $response->assertSessionHas('error');
    expect(Category::find($parent->id))->not->toBeNull();
});

test('bulk delete fails with empty array', function () {
    catDelContext();
    $this->delete(route('catalog.categories.bulk-destroy'), ['categories' => []])
        ->assertSessionHasErrors('categories');
});

test('bulk delete fails with invalid UUID', function () {
    catDelContext();
    $this->delete(route('catalog.categories.bulk-destroy'), ['categories' => ['not-a-uuid']])
        ->assertSessionHasErrors('categories.0');
});

test('user without permission gets 403', function () {
    [$owner, $company] = catDelContext(CompanyRole::Owner);
    $cat = catDelCategory($owner, $company, 'Protected');
    $viewer = User::factory()->create();
    CompanyMembership::factory()->create(['user_id' => $viewer, 'company_id' => $company, 'role' => CompanyRole::Viewer]);
    $this->actingAs($viewer);
    app(CurrentCompany::class)->set($company);

    $this->delete(route('catalog.categories.destroy', $cat->uuid))->assertForbidden();
    $this->delete(route('catalog.categories.bulk-destroy'), ['categories' => [$cat->uuid]])->assertForbidden();
    expect(Category::find($cat->id))->not->toBeNull();
});

test('foreign company categories not deleted', function () {
    [$actor, $company] = catDelContext();
    $owned = catDelCategory($actor, $company, 'Owned');
    $foreignCompany = Company::factory()->create();
    CompanyMembership::factory()->owner()->create(['company_id' => $foreignCompany, 'user_id' => $actor]);
    app(CurrentCompany::class)->set($foreignCompany);
    $foreign = catDelCategory($actor, $foreignCompany, 'Foreign');
    app(CurrentCompany::class)->set($company);

    $this->delete(route('catalog.categories.bulk-destroy'), ['categories' => [$owned->uuid, $foreign->uuid]]);

    expect(Category::withTrashed()->find($owned->id)?->trashed())->toBeTrue()
        ->and(Category::find($foreign->id))->not->toBeNull();
});

test('bulk delete rejects duplicate UUIDs', function () {
    [$actor, $company] = catDelContext();
    $cat = catDelCategory($actor, $company, 'Dedup');
    $this->delete(route('catalog.categories.bulk-destroy'), ['categories' => [$cat->uuid, $cat->uuid]])
        ->assertSessionHasErrors('categories.*');
    expect(Category::find($cat->id))->not->toBeNull();
});

test('audit log created on delete', function () {
    [$actor, $company] = catDelContext();
    $cat = catDelCategory($actor, $company, 'Audit Cat');
    $before = AuditLog::count();
    $this->delete(route('catalog.categories.destroy', $cat->uuid));
    expect(AuditLog::count())->toBe($before + 1);
});
