<?php

use App\Actions\Catalog\Products\ProductAggregateCreator;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\ProductCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;

uses(RefreshDatabase::class);

/** @return array{User, Company, CompanyMembership} */
function r15UiContext(CompanyRole $role = CompanyRole::Owner): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::factory()->create();
    $membership = CompanyMembership::factory()->create([
        'user_id' => $user,
        'company_id' => $company,
        'role' => $role,
    ]);

    return [$user, $company, $membership];
}

function r15UiCategory(
    Company $company,
    User $actor,
    string $name,
    CategoryStatus $status = CategoryStatus::Active,
): Category {
    $slug = str($name)->slug()->toString();
    $category = new Category;
    $category->forceFill([
        'company_id' => $company->id,
        'parent_id' => null,
        'depth' => 0,
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

function r15UiProduct(Company $company, User $actor, string $name, ?Category $primary = null): Product
{
    $slug = str($name)->slug()->toString();
    $product = app(ProductAggregateCreator::class)->create($actor, $company, [
        'name' => $name,
        'slug' => $slug,
        'short_description' => "Description for {$name}",
        'description' => null,
        'brand' => 'Test brand',
        'manufacturer' => 'Test manufacturer',
    ], [
        'name' => 'Default',
        'sku' => 'TEST-'.str($slug)->upper()->replace('-', '_')->toString(),
        'sku_normalized' => 'test_'.str($slug)->replace('-', '_')->toString(),
        'gtin' => null,
        'mpn' => null,
        'sort_order' => 0,
    ]);

    if ($primary !== null) {
        app(ProductCategoryService::class)->sync($company, $product, $primary->uuid, []);
    }

    return $product->refresh();
}

test('viewer sees only current company products and read only product details', function () {
    [$viewer, $company] = r15UiContext(CompanyRole::Viewer);
    $category = r15UiCategory($company, $viewer, 'Visible category');
    $visible = r15UiProduct($company, $viewer, 'Visible product', $category);
    $foreignCompany = Company::factory()->create();
    r15UiProduct($foreignCompany, $viewer, 'Foreign secret product');

    $this->actingAs($viewer)->get(route('catalog.products.index'))
        ->assertOk()
        ->assertSee('Visible product')
        ->assertDontSee('Foreign secret product')
        ->assertSee('Read only')
        ->assertDontSee('Create product')
        ->assertDontSee('Edit');

    $this->get(route('catalog.products.show', $visible->uuid))
        ->assertOk()
        ->assertSee('Visible product')
        ->assertSee('Default variant')
        ->assertSee('TEST-VISIBLE_PRODUCT')
        ->assertDontSee('Edit product');
});

test('product index is paginated and loads row relations and category counts', function () {
    [$owner, $company] = r15UiContext();
    $category = r15UiCategory($company, $owner, 'Pagination category');

    foreach (range(1, 26) as $number) {
        r15UiProduct($company, $owner, sprintf('Paginated product %02d', $number), $category);
    }

    $this->actingAs($owner)->get(route('catalog.products.index'))
        ->assertOk()
        ->assertViewHas('products', function (LengthAwarePaginator $products): bool {
            return $products->perPage() === 25
                && $products->total() === 26
                && $products->getCollection()->every(fn (Product $product): bool => $product->relationLoaded('primaryCategory')
                    && $product->relationLoaded('defaultVariant')
                    && $product->categories_count === 1);
        });

    $this->get(route('catalog.products.index', ['page' => 2]))
        ->assertOk()
        ->assertViewHas('products', fn (LengthAwarePaginator $products): bool => $products->count() === 1);
});

test('owner create form is tenant scoped and store preserves old input and trusted fields', function () {
    [$owner, $company] = r15UiContext();
    $primary = r15UiCategory($company, $owner, 'Available primary');
    $additional = r15UiCategory($company, $owner, 'Available additional');
    r15UiCategory($company, $owner, 'Archived option', CategoryStatus::Archived);
    $foreignCompany = Company::factory()->create();
    r15UiCategory($foreignCompany, $owner, 'Foreign option');

    $this->actingAs($owner)->get(route('catalog.products.create'))
        ->assertOk()
        ->assertSee('Available primary')
        ->assertSee('Available additional')
        ->assertDontSee('Archived option')
        ->assertDontSee('Foreign option');

    $this->from(route('catalog.products.create'))->post(route('catalog.products.store'), [
        'name' => '',
        'slug' => 'remember-this-slug',
        'description' => 'Remember this description',
        'category_uuids' => [],
    ])->assertRedirect(route('catalog.products.create'))
        ->assertSessionHasErrors('name')
        ->assertSessionHasInput('description', 'Remember this description');

    $response = $this->post(route('catalog.products.store'), [
        'name' => 'Created through UI',
        'slug' => '',
        'short_description' => 'Short',
        'description' => 'Long',
        'brand' => 'Brand',
        'manufacturer' => 'Maker',
        'primary_category_uuid' => $primary->uuid,
        'category_uuids' => [$additional->uuid],
        'company_id' => $foreignCompany->id,
        'status' => 'active',
        'published_at' => now()->toAtomString(),
    ]);
    $product = Product::query()->where('slug', 'created-through-ui')->sole();

    $response->assertRedirect(route('catalog.products.show', $product->uuid))
        ->assertSessionHas('success', 'Product created.');
    expect($product->company_id)->toBe($company->id)
        ->and($product->status->value)->toBe('draft')
        ->and($product->published_at)->toBeNull()
        ->and($product->primary_category_id)->toBe($primary->id)
        ->and($product->categories()->pluck('categories.id')->sort()->values()->all())
        ->toBe(collect([$primary->id, $additional->id])->sort()->values()->all());

    $this->get(route('catalog.products.show', $product->uuid))
        ->assertOk()
        ->assertSee('Product created.')
        ->assertSee('Created through UI');
});

test('editor sees selected categories and can update managed product fields', function () {
    [$editor, $company] = r15UiContext(CompanyRole::Editor);
    $oldPrimary = r15UiCategory($company, $editor, 'Old primary');
    $newPrimary = r15UiCategory($company, $editor, 'New primary');
    $additional = r15UiCategory($company, $editor, 'Selected additional');
    $product = r15UiProduct($company, $editor, 'Editable product', $oldPrimary);
    app(ProductCategoryService::class)->sync($company, $product, $oldPrimary->uuid, [$additional->uuid]);

    $this->actingAs($editor)->get(route('catalog.products.edit', $product->uuid))
        ->assertOk()
        ->assertSee('Editable product')
        ->assertSee('value="'.$oldPrimary->uuid.'" selected', false)
        ->assertSee('value="'.$additional->uuid.'"', false)
        ->assertSee('Identifiers and the default selection are managed on the variants screen.')
        ->assertSee('Manage variants');

    $this->patch(route('catalog.products.update', $product->uuid), [
        'name' => 'Updated through UI',
        'slug' => ' Updated Through UI ',
        'short_description' => 'Updated short',
        'description' => 'Updated long',
        'brand' => 'Updated brand',
        'manufacturer' => 'Updated maker',
        'primary_category_uuid' => $newPrimary->uuid,
        'category_uuids' => [$additional->uuid],
        'company_id' => Company::factory()->create()->id,
        'status' => 'active',
    ])->assertRedirect(route('catalog.products.show', $product->uuid))
        ->assertSessionHas('success', 'Product updated.');

    expect($product->fresh()?->name)->toBe('Updated through UI')
        ->and($product->fresh()?->slug)->toBe('updated-through-ui')
        ->and($product->fresh()?->company_id)->toBe($company->id)
        ->and($product->fresh()?->status->value)->toBe('draft')
        ->and($product->fresh()?->primary_category_id)->toBe($newPrimary->id);
});

test('viewer cannot open or submit product mutations', function () {
    [$viewer, $company] = r15UiContext(CompanyRole::Viewer);
    $product = r15UiProduct($company, $viewer, 'Protected product');
    $this->actingAs($viewer);

    $this->get(route('catalog.products.create'))->assertForbidden();
    $this->post(route('catalog.products.store'), [
        'name' => 'Denied', 'slug' => 'denied', 'category_uuids' => [],
    ])->assertForbidden();
    $this->get(route('catalog.products.edit', $product->uuid))->assertForbidden();
    $this->patch(route('catalog.products.update', $product->uuid), [
        'name' => 'Changed', 'slug' => 'changed', 'category_uuids' => [],
    ])->assertForbidden();

    expect($product->fresh()?->name)->toBe('Protected product');
});

test('wrong tenant product UUID is concealed for every model route', function () {
    [$owner] = r15UiContext();
    $foreignCompany = Company::factory()->create();
    $foreignProduct = r15UiProduct($foreignCompany, $owner, 'Foreign product');
    $this->actingAs($owner);

    $this->get(route('catalog.products.show', $foreignProduct->uuid))->assertNotFound();
    $this->get(route('catalog.products.edit', $foreignProduct->uuid))->assertNotFound();
    $this->patch(route('catalog.products.update', $foreignProduct->uuid), [
        'name' => 'Attempt', 'slug' => 'attempt', 'category_uuids' => [],
    ])->assertNotFound();
});

test('product routes enforce authentication verification company selection and active company', function () {
    [$user, $company, $membership] = r15UiContext();

    $this->get(route('catalog.products.index'))->assertRedirect(route('login'));

    $user->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($user)->get(route('catalog.products.index'))
        ->assertRedirect(route('verification.notice'));

    $user->forceFill(['email_verified_at' => now()])->save();
    $membership->delete();
    $this->actingAs($user)->get(route('catalog.products.index'))
        ->assertRedirect(route('companies.none'));

    CompanyMembership::factory()->owner()->create(['user_id' => $user, 'company_id' => $company]);
    $company->forceFill(['status' => CompanyStatus::Suspended])->save();
    $this->actingAs($user)->get(route('catalog.products.index'))
        ->assertRedirect(route('company.suspended'));
});

test('product routes expose only the approved HTTP methods and names', function () {
    [$owner, $company] = r15UiContext();
    $product = r15UiProduct($company, $owner, 'Method protected product');
    $this->actingAs($owner);

    expect(route('catalog.products.index'))->toEndWith('/catalog/products')
        ->and(route('catalog.products.create'))->toEndWith('/catalog/products/create')
        ->and(route('catalog.products.store'))->toEndWith('/catalog/products')
        ->and(route('catalog.products.show', $product->uuid))->toEndWith('/catalog/products/'.$product->uuid)
        ->and(route('catalog.products.edit', $product->uuid))->toEndWith('/catalog/products/'.$product->uuid.'/edit')
        ->and(route('catalog.products.update', $product->uuid))->toEndWith('/catalog/products/'.$product->uuid);

    $this->get('/catalog/products/'.$product->uuid.'/delete')->assertNotFound();
    $this->delete(route('catalog.products.show', $product->uuid))->assertMethodNotAllowed();
});
