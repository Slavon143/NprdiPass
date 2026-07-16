<?php

use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\Documents\ProductDocumentStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Storage;

describe('Document security', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('cross-company document access returns 404', function () {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $companyA->id,
            'role' => CompanyRole::Owner,
        ]);
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $companyB->id,
            'role' => CompanyRole::Owner,
        ]);

        test()->actingAs($user);
        app(CurrentCompany::class)->set($companyA);

        $productB = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $companyB->id,
            'name' => 'B Product',
            'slug' => 'b-product-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $docB = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $companyB->id,
            'product_id' => $productB->id,
            'status' => ProductDocumentStatus::Active->value,
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = test()->get(
            route('catalog.products.documents.show', [$productB->uuid, $docB->uuid])
        );

        $response->assertNotFound();
    });

    test('viewer cannot create document', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => CompanyRole::Viewer,
        ]);

        test()->actingAs($user);
        app(CurrentCompany::class)->set($company);

        $product = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Test Product',
            'slug' => 'test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = test()->get(route('catalog.products.documents.create', $product->uuid));
        $response->assertForbidden();
    });

    test('viewer can view documents', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);

        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => CompanyRole::Viewer,
        ]);

        test()->actingAs($user);
        app(CurrentCompany::class)->set($company);

        $product = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Test Product',
            'slug' => 'test-product-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = test()->get(route('catalog.products.documents.index', $product->uuid));
        $response->assertOk();
    });
});
