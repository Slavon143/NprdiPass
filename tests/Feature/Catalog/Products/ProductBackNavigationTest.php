<?php

use App\Actions\Catalog\Products\CreateProductAction;
use App\Enums\Catalog\CategoryStatus;
use App\Enums\CompanyRole;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{User, Company, CompanyMembership} */
function backContext(CompanyRole $role = CompanyRole::Owner): array
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

function backProduct(User $actor, Company $company, string $name = 'Back Test Product'): Product
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
        'short_description' => 'Back test',
        'description' => null,
        'brand' => null,
        'manufacturer' => null,
    ], $category->uuid);
}

test('show page has back button', function () {
    [$actor, $company] = backContext();
    $product = backProduct($actor, $company);

    $this->get(route('catalog.products.show', $product->uuid))
        ->assertOk()
        ->assertSee('Back to products', false);
});

test('product list open and edit links carry the current filter context', function () {
    [$actor, $company] = backContext();
    $product = backProduct($actor, $company);
    $returnUrl = route('catalog.products.index', [
        'q' => 'Back Test',
        'sort' => 'name',
        'direction' => 'asc',
    ]);

    $html = html_entity_decode($this->get($returnUrl)->assertOk()->getContent());

    foreach (['catalog.products.show', 'catalog.products.edit'] as $routeName) {
        $targetUrl = route($routeName, $product->uuid);
        $matched = preg_match(
            '/href="'.preg_quote($targetUrl, '/').'\?([^\"]+)"/',
            $html,
            $matches,
        );

        expect($matched)->toBe(1);
        parse_str($matches[1], $targetQuery);
        expect($targetQuery)->toHaveKey('return');

        $actualReturn = parse_url((string) $targetQuery['return']);
        $expectedReturn = parse_url($returnUrl);
        parse_str((string) ($actualReturn['query'] ?? ''), $actualQuery);
        parse_str((string) ($expectedReturn['query'] ?? ''), $expectedQuery);
        ksort($actualQuery);
        ksort($expectedQuery);

        expect($actualReturn['path'] ?? null)->toBe($expectedReturn['path'] ?? null)
            ->and($actualQuery)->toBe($expectedQuery);
    }
});

test('edit page has back button', function () {
    [$actor, $company] = backContext();
    $product = backProduct($actor, $company);

    $this->get(route('catalog.products.edit', $product->uuid))
        ->assertOk()
        ->assertSee('Back to products', false);
});

test('show page back button defaults to catalog products index', function () {
    [$actor, $company] = backContext();
    $product = backProduct($actor, $company);

    $this->get(route('catalog.products.show', $product->uuid))
        ->assertOk()
        ->assertSee(route('catalog.products.index'), false);
});

test('show page back button preserves return URL with filters', function () {
    [$actor, $company] = backContext();
    $product = backProduct($actor, $company);

    $returnUrl = route('catalog.products.index', ['q' => 'searchtest', 'sort' => 'name', 'page' => 3]);

    $response = $this->get(route('catalog.products.show', [
        'product' => $product->uuid,
        'return' => $returnUrl,
    ]));

    $response->assertOk()
        ->assertSee('q=searchtest', false)
        ->assertSee('sort=name', false)
        ->assertSee('page=3', false);
});

test('show page returns to index with filters after update preserves return URL', function () {
    [$actor, $company] = backContext();
    $product = backProduct($actor, $company);

    $returnUrl = route('catalog.products.index', ['q' => 'updated', 'page' => 2]);

    $response = $this->patch(route('catalog.products.update', [
        'product' => $product->uuid,
        'return' => $returnUrl,
    ]), [
        'name' => 'Updated Name',
        'slug' => 'updated-name-'.bin2hex(random_bytes(4)),
        'category_uuids' => [],
    ]);

    $response->assertRedirect();

    $target = $response->getTargetUrl();
    expect($target)->toContain(urlencode($returnUrl));
});

test('show page ignores external return URL', function () {
    [$actor, $company] = backContext();
    $product = backProduct($actor, $company);

    $response = $this->get(route('catalog.products.show', [
        'product' => $product->uuid,
        'return' => 'https://evil.com/phishing',
    ]));

    $response->assertOk()
        ->assertDontSee('evil.com', false)
        ->assertSee(route('catalog.products.index'), false);
});

test('show page falls back to products index for non product module return URL', function () {
    [$actor, $company] = backContext();
    $product = backProduct($actor, $company);

    $response = $this->get(route('catalog.products.show', [
        'product' => $product->uuid,
        'return' => route('dashboard'),
    ]));

    $response->assertOk();
});
