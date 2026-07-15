<?php

use App\Enums\ApiTokenAbility;
use App\Enums\CompanyRole;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

function apiCreateCategory(Company $company, User $actor, string $name, ?Category $parent = null, string $status = 'active'): Category
{
    $slug = str($name)->slug()->toString();
    $cat = new Category;
    $cat->forceFill([
        'company_id' => $company->getKey(),
        'parent_id' => $parent?->getKey(),
        'depth' => $parent ? $parent->depth + 1 : 0,
        'name' => $name,
        'slug' => $slug,
        'slug_normalized' => $slug,
        'sort_order' => 10,
        'status' => $status,
        'created_by' => $actor->getKey(),
        'updated_by' => $actor->getKey(),
    ])->save();

    return $cat->refresh();
}

test('can list categories', function () {
    [$user, $company] = apiCatalogContext();
    apiCreateCategory($company, $user, 'Test Cat');

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], 'categories');
    expect($res->status())->toBe(200);
    expect(count($res->json('data')))->toBeGreaterThanOrEqual(1);
});

test('can create root category', function () {
    [$user, $company] = apiCatalogContext();
    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'categories', [
        'name' => 'Root Category',
        'sort_order' => 10,
    ]);
    expect($res->status())->toBe(201);
    expect($res->json('data.name'))->toBe('Root Category');
});

test('can create child category', function () {
    [$user, $company] = apiCatalogContext();
    $parent = apiCreateCategory($company, $user, 'Parent');

    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'categories', [
        'name' => 'Child Category',
        'parent_uuid' => $parent->uuid,
        'sort_order' => 10,
    ]);
    expect($res->status())->toBe(201);
});

test('can show category', function () {
    [$user, $company] = apiCatalogContext();
    $cat = apiCreateCategory($company, $user, 'Show Me');

    $res = apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "categories/{$cat->uuid}");
    expect($res->status())->toBe(200);
    expect($res->json('data.name'))->toBe('Show Me');
});

test('can update category', function () {
    [$user, $company] = apiCatalogContext();
    $cat = apiCreateCategory($company, $user, 'Old Name');

    $res = apiPatch($user, $company, [ApiTokenAbility::CatalogWrite->value], "categories/{$cat->uuid}", [
        'name' => 'New Name',
    ]);
    expect($res->status())->toBe(200);
    expect($res->json('data.name'))->toBe('New Name');
});

test('can archive category', function () {
    [$user, $company] = apiCatalogContext();
    $cat = apiCreateCategory($company, $user, 'Archivable');

    $res = test()->withToken(apiToken($user, $company, [
        ApiTokenAbility::CatalogLifecycle->value, ApiTokenAbility::CatalogRead->value,
    ]))->postJson(apiUrl("categories/{$cat->uuid}/archive"));
    expect($res->status())->toBe(200);
});

test('can restore category', function () {
    [$user, $company] = apiCatalogContext();
    $cat = apiCreateCategory($company, $user, 'Restorable', null, 'archived');

    $res = test()->withToken(apiToken($user, $company, [
        ApiTokenAbility::CatalogLifecycle->value, ApiTokenAbility::CatalogRead->value,
    ]))->postJson(apiUrl("categories/{$cat->uuid}/restore"));
    expect($res->status())->toBe(200);
});

test('wrong tenant category returns 404', function () {
    [$user, $company] = apiCatalogContext();
    $otherCompany = Company::factory()->create();
    $otherUser = User::factory()->create(['email_verified_at' => now()]);
    $cat = apiCreateCategory($otherCompany, $otherUser, 'Foreign');

    apiGet($user, $company, [ApiTokenAbility::CatalogRead->value], "categories/{$cat->uuid}")
        ->assertNotFound();
});

test('validation errors return 422', function () {
    [$user, $company] = apiCatalogContext();
    $res = apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'categories', ['sort_order' => -1]);
    expect($res->status())->toBe(422);
});

test('viewer cannot create category', function () {
    [$user, $company] = apiCatalogContext(CompanyRole::Viewer);
    apiPost($user, $company, [ApiTokenAbility::CatalogWrite->value], 'categories', [
        'name' => 'Test', 'sort_order' => 0,
    ])->assertStatus(403);
});
