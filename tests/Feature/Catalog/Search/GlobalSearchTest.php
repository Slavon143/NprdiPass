<?php

use App\Enums\Catalog\ProductStatus;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;

test('header search uses the catalog query parameter and suggestions endpoint', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    CompanyMembership::factory()->owner()->create([
        'user_id' => $user,
        'company_id' => $company,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('name="q"', false)
        ->assertSee('data-suggestions-url="'.route('catalog.products.suggestions').'"', false)
        ->assertSee('x-data="globalSearch"', false)
        ->assertDontSee('name="search"', false);
});

test('header suggestions match products only in the selected company', function () {
    $user = User::factory()->create();
    $selectedCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    foreach ([$selectedCompany, $otherCompany] as $company) {
        CompanyMembership::factory()->owner()->create([
            'user_id' => $user,
            'company_id' => $company,
        ]);
    }

    foreach ([[$selectedCompany, 'Machine Guard Panel'], [$otherCompany, 'Machine Guard Foreign']] as [$company, $name]) {
        $product = new Product;
        $product->forceFill([
            'company_id' => $company->getKey(),
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'slug_normalized' => str($name)->slug()->value(),
            'status' => ProductStatus::Active,
            'created_by' => $user->getKey(),
        ])->save();
    }

    $this->actingAs($user)
        ->withSession([config('tenancy.session_key') => $selectedCompany->getKey()])
        ->getJson(route('catalog.products.suggestions', ['q' => 'Machine Guard']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Machine Guard Panel');
});

test('header suggestions require authentication', function () {
    $this->get(route('catalog.products.suggestions', ['q' => 'Machine']))
        ->assertRedirect(route('login'));
});
