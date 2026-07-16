<?php

use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Support\Facades\Storage;

describe('Document Web UI', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    function createOwnerContext(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => CompanyRole::Owner,
        ]);
        test()->actingAs($user);
        app(CurrentCompany::class)->set($company);

        return [$company, $user];
    }

    function createProduct(Company $company, User $user): Product
    {
        return Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Web Test Product',
            'slug' => 'web-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    test('document index page loads', function () {
        [$company, $user] = createOwnerContext();
        $product = createProduct($company, $user);

        $response = test()->get(route('catalog.products.documents.index', $product->uuid));

        $response->assertOk();
    });

    test('document create page loads', function () {
        [$company, $user] = createOwnerContext();
        $product = createProduct($company, $user);

        $response = test()->get(route('catalog.products.documents.create', $product->uuid));

        $response->assertOk();
    });

    test('document show page loads', function () {
        [$company, $user] = createOwnerContext();
        $product = createProduct($company, $user);

        $version = new ProductDocumentVersion;
        $version->forceFill([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'document_id' => 0,
            'version_number' => 1,
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'Web Document',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_extension' => 'pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => str_repeat('a', 64),
            'storage_key' => 'test/web-'.fake()->uuid().'.pdf',
            'created_by_user_id' => $user->id,
        ]);

        $document = ProductDocument::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'product_id' => $product->id,
            'status' => ProductDocumentStatus::Active->value,
            'current_version_id' => null,
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $version->document_id = $document->getKey();
        $version->save();
        $document->forceFill(['current_version_id' => $version->getKey()])->save();

        $response = test()->get(route('catalog.products.documents.show', [$product->uuid, $document->uuid]));

        $response->assertOk();
        $response->assertSee('Web Document');
    });
});
