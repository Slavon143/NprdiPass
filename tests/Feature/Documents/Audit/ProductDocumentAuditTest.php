<?php

use App\Actions\Catalog\Documents\CreateProductDocumentAction;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('Document audit', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('create document generates exactly one audit event', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => CompanyRole::Owner,
        ]);
        test()->actingAs($user);
        app(CurrentCompany::class)->set($company);

        $product = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Audit Product',
            'slug' => 'audit-product-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeCount = AuditLog::query()->count();

        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('audit.pdf', $pdfContent);

        $action = app(CreateProductDocumentAction::class);
        $action->execute($user, $company, $product, [
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'Audit Cert',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
            'issuer_name' => 'Test Issuer',
            'issue_date' => now()->subDays(5)->toDateString(),
        ], $file);

        $afterCount = AuditLog::query()->count();

        expect($afterCount)->toBe($beforeCount + 1);

        $audit = AuditLog::query()->latest('id')->first();
        expect($audit->event)->toBe(AuditEvent::CatalogDocumentCreated->value);
    });

    test('failed action produces zero audit events', function () {
        $company = Company::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        CompanyMembership::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => CompanyRole::Owner,
        ]);
        test()->actingAs($user);
        app(CurrentCompany::class)->set($company);

        $product = Product::query()->forceCreate([
            'uuid' => fake()->uuid(),
            'company_id' => $company->id,
            'name' => 'Fail Product',
            'slug' => 'fail-product-'.fake()->unique()->slug(1),
            'slug_normalized' => fake()->unique()->slug(1),
            'status' => ProductStatus::Active->value,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeCount = AuditLog::query()->count();

        $file = UploadedFile::fake()->create('bad.txt', 100);

        try {
            $action = app(CreateProductDocumentAction::class);
            $action->execute($user, $company, $product, [
                'document_type' => 'instruction',
                'title' => 'Fail',
                'language' => 'sv',
                'visibility' => 'internal',
            ], $file);
        } catch (Throwable) {
        }

        $afterCount = AuditLog::query()->count();

        expect($afterCount)->toBe($beforeCount);
    });
});
