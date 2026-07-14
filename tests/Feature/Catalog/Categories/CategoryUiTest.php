<?php

use App\Enums\Catalog\CategoryStatus;
use App\Enums\CompanyRole;
use App\Models\Catalog\Category;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{User, Company} */
function r14UiContext(CompanyRole $role): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    CompanyMembership::factory()->create([
        'user_id' => $user,
        'company_id' => $company,
        'role' => $role,
    ]);

    return [$user, $company];
}

function r14UiCategory(
    Company $company,
    User $actor,
    string $name,
    ?Category $parent = null,
    CategoryStatus $status = CategoryStatus::Active,
): Category {
    $slug = str($name)->slug()->toString();
    $category = new Category;
    $category->forceFill([
        'company_id' => $company->id,
        'parent_id' => $parent?->id,
        'depth' => $parent === null ? 0 : $parent->depth + 1,
        'name' => $name,
        'slug' => $slug,
        'slug_normalized' => $slug,
        'sort_order' => 10,
        'status' => $status,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ])->save();

    return $category->refresh();
}

test('viewer sees only current company categories in read-only paginated index', function () {
    [$viewer, $company] = r14UiContext(CompanyRole::Viewer);
    r14UiCategory($company, $viewer, 'Visible category');
    $foreign = Company::factory()->create();
    r14UiCategory($foreign, $viewer, 'Foreign secret category');

    $this->actingAs($viewer)->get(route('catalog.categories.index'))
        ->assertOk()
        ->assertSee('Visible category')
        ->assertDontSee('Foreign secret category')
        ->assertSee('Read only')
        ->assertDontSee('Create category')
        ->assertDontSee('Edit');
});

test('editor and viewer cannot open or submit category mutations', function (CompanyRole $role) {
    [$user, $company] = r14UiContext($role);
    $category = r14UiCategory($company, $user, 'Protected');

    $this->actingAs($user)->get(route('catalog.categories.create'))->assertForbidden();
    $this->post(route('catalog.categories.store'), [
        'name' => 'Denied',
        'slug' => 'denied',
        'sort_order' => 0,
    ])->assertForbidden();
    $this->patch(route('catalog.categories.update', $category->uuid), [
        'name' => 'Changed',
        'slug' => 'changed',
        'sort_order' => 0,
    ])->assertForbidden();

    expect($category->fresh()?->name)->toBe('Protected');
})->with([
    'editor' => [CompanyRole::Editor],
    'viewer' => [CompanyRole::Viewer],
]);

test('admin create form is tenant scoped and category creation preserves validation input', function () {
    [$admin, $company] = r14UiContext(CompanyRole::Admin);
    $parent = r14UiCategory($company, $admin, 'Available parent');
    r14UiCategory($company, $admin, 'Archived parent', null, CategoryStatus::Archived);
    $foreign = Company::factory()->create();
    r14UiCategory($foreign, $admin, 'Foreign parent');

    $this->actingAs($admin)->get(route('catalog.categories.create'))
        ->assertOk()
        ->assertSee('Available parent')
        ->assertDontSee('Archived parent')
        ->assertDontSee('Foreign parent');

    $this->from(route('catalog.categories.create'))->post(route('catalog.categories.store'), [
        'name' => '',
        'slug' => 'remember-me',
        'description' => 'Remember this description',
        'sort_order' => 0,
    ])->assertRedirect(route('catalog.categories.create'))
        ->assertSessionHasErrors('name')
        ->assertSessionHasInput('description', 'Remember this description');

    $response = $this->post(route('catalog.categories.store'), [
        'name' => 'New Child',
        'slug' => '',
        'description' => 'Created from UI',
        'parent_uuid' => $parent->uuid,
        'sort_order' => 20,
        'company_id' => $foreign->id,
        'depth' => 5,
        'status' => 'archived',
    ]);
    $category = Category::query()->where('slug', 'new-child')->sole();

    $response->assertRedirect(route('catalog.categories.edit', $category->uuid))
        ->assertSessionHas('success', 'Category created.');
    expect($category->company_id)->toBe($company->id)
        ->and($category->parent_id)->toBe($parent->id)
        ->and($category->depth)->toBe(1)
        ->and($category->status)->toBe(CategoryStatus::Active);
});

test('edit parent list excludes self descendants archived and foreign categories', function () {
    [$owner, $company] = r14UiContext(CompanyRole::Owner);
    $root = r14UiCategory($company, $owner, 'Editing root');
    r14UiCategory($company, $owner, 'Descendant option', $root);
    r14UiCategory($company, $owner, 'Valid option');
    r14UiCategory($company, $owner, 'Archived option', null, CategoryStatus::Archived);
    $foreign = Company::factory()->create();
    r14UiCategory($foreign, $owner, 'Foreign option');

    $this->actingAs($owner)->get(route('catalog.categories.edit', $root->uuid))
        ->assertOk()
        ->assertSee('Valid option')
        ->assertDontSee('Descendant option')
        ->assertDontSee('Archived option')
        ->assertDontSee('Foreign option');
});

test('owner can update move reorder archive and restore through protected routes', function () {
    [$owner, $company] = r14UiContext(CompanyRole::Owner);
    $a = r14UiCategory($company, $owner, 'A');
    $b = r14UiCategory($company, $owner, 'B');
    $child = r14UiCategory($company, $owner, 'Child', $a);

    $this->actingAs($owner)->patch(route('catalog.categories.update', $child->uuid), [
        'name' => 'Updated child',
        'slug' => 'Updated Child',
        'description' => 'Safe',
        'sort_order' => 30,
        'parent_id' => $b->id,
    ])->assertRedirect(route('catalog.categories.edit', $child->uuid))
        ->assertSessionHas('success', 'Category updated.');
    expect($child->fresh()?->parent_id)->toBe($a->id);

    $this->patch(route('catalog.categories.move', $child->uuid), ['parent_uuid' => $b->uuid])
        ->assertRedirect(route('catalog.categories.edit', $child->uuid))
        ->assertSessionHas('success', 'Category moved.');
    expect($child->fresh()?->parent_id)->toBe($b->id);

    $this->patch(route('catalog.categories.reorder'), [
        'ordered_uuids' => [$b->uuid, $a->uuid],
    ])->assertSessionHas('success', 'Category order updated.');
    expect($b->fresh()?->sort_order)->toBe(10)->and($a->fresh()?->sort_order)->toBe(20);

    $this->patch(route('catalog.categories.archive', $a->uuid))
        ->assertRedirect(route('catalog.categories.index'))
        ->assertSessionHas('success', 'Category archived.');
    expect($a->fresh()?->status)->toBe(CategoryStatus::Archived);

    $this->patch(route('catalog.categories.restore', $a->uuid))
        ->assertRedirect(route('catalog.categories.edit', $a->uuid))
        ->assertSessionHas('success', 'Category restored.');
    expect($a->fresh()?->status)->toBe(CategoryStatus::Active);
});

test('wrong tenant category UUID is concealed for every model route', function () {
    [$owner, $company] = r14UiContext(CompanyRole::Owner);
    $foreign = Company::factory()->create();
    $foreignCategory = r14UiCategory($foreign, $owner, 'Foreign category');
    $this->actingAs($owner);

    $this->get(route('catalog.categories.edit', $foreignCategory->uuid))->assertNotFound();
    $this->patch(route('catalog.categories.update', $foreignCategory->uuid), [
        'name' => 'Attempt', 'slug' => 'attempt', 'sort_order' => 0,
    ])->assertNotFound();
    $this->patch(route('catalog.categories.move', $foreignCategory->uuid), [])->assertNotFound();
    $this->patch(route('catalog.categories.archive', $foreignCategory->uuid))->assertNotFound();
    $this->patch(route('catalog.categories.restore', $foreignCategory->uuid))->assertNotFound();
});

test('category routes enforce authentication verification and mutation methods', function () {
    [$user] = r14UiContext(CompanyRole::Owner);
    $this->get(route('catalog.categories.index'))->assertRedirect(route('login'));

    $user->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($user)->get(route('catalog.categories.index'))->assertRedirect(route('verification.notice'));

    $user->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($user)->get('/settings/catalog/categories/reorder')->assertMethodNotAllowed();
});
