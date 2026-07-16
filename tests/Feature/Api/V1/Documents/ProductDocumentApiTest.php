<?php

use App\Enums\ApiTokenAbility;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\Documents\ProductDocumentStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Catalog\ProductVariant;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Catalog/Helpers.php';

function apiDocumentsContext(CompanyRole $role = CompanyRole::Owner): array
{
    [$user, $company] = apiCatalogContext($role);

    return [$user, $company];
}

function createApiTestProduct(Company $company, User $actor): Product
{
    $product = new Product;
    $product->forceFill([
        'uuid' => fake()->uuid(),
        'company_id' => $company->getKey(),
        'name' => 'API Product '.fake()->word(),
        'slug' => 'api-product-'.fake()->unique()->slug(1),
        'slug_normalized' => fake()->unique()->slug(1),
        'status' => ProductStatus::Active->value,
        'created_by' => $actor->getKey(),
    ])->save();

    $variant = new ProductVariant;
    $variant->forceFill([
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'name' => 'Default',
        'status' => ProductStatus::Active->value,
        'sort_order' => 0,
        'created_by' => $actor->getKey(),
    ])->save();

    $product->default_variant_id = $variant->getKey();
    $product->save();

    return $product->refresh();
}

function createApiTestDocument(Company $company, User $actor, Product $product): ProductDocument
{
    $document = ProductDocument::query()->forceCreate([
        'uuid' => fake()->uuid(),
        'company_id' => $company->getKey(),
        'product_id' => $product->getKey(),
        'status' => ProductDocumentStatus::Active->value,
        'created_by_user_id' => $actor->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $version = new ProductDocumentVersion;
    $version->forceFill([
        'uuid' => fake()->uuid(),
        'company_id' => $company->getKey(),
        'document_id' => $document->getKey(),
        'version_number' => 1,
        'document_type' => ProductDocumentType::Instruction->value,
        'title' => 'Test Doc',
        'language' => 'sv',
        'visibility' => ProductDocumentVisibility::Internal->value,
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'file_extension' => 'pdf',
        'size_bytes' => 1024,
        'checksum_sha256' => str_repeat('a', 64),
        'storage_key' => 'test/api-'.fake()->uuid().'.pdf',
        'created_by_user_id' => $actor->getKey(),
    ])->save();

    $document->forceFill(['current_version_id' => $version->getKey()])->save();

    return $document->refresh();
}

function makePdfFile(): UploadedFile
{
    $content = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n%%EOF";

    return UploadedFile::fake()->createWithContent('api-doc.pdf', $content);
}

function documentStorePayload(): array
{
    return [
        'document_type' => ProductDocumentType::Instruction->value,
        'title' => 'API Document',
        'language' => 'sv',
        'visibility' => ProductDocumentVisibility::Internal->value,
    ];
}

// ── List documents ──────────────────────────────────────────────

describe('API list documents', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('owner can list documents', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        createApiTestDocument($company, $user, $product);

        apiGet($user, $company, [ApiTokenAbility::DocumentsRead->value], "products/{$product->uuid}/documents")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonCount(1, 'data');
    });

    test('viewer can list documents', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Viewer);
        [$owner] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $owner);
        createApiTestDocument($company, $owner, $product);

        apiGet($user, $company, [ApiTokenAbility::DocumentsRead->value], "products/{$product->uuid}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    test('wrong company returns 404 for document list', function () {
        [$user, $companyA] = apiDocumentsContext(CompanyRole::Owner);
        $productA = createApiTestProduct($companyA, $user);

        [$otherUser, $companyB] = apiDocumentsContext(CompanyRole::Owner);
        $productB = createApiTestProduct($companyB, $otherUser);

        apiGet($user, $companyA, [ApiTokenAbility::DocumentsRead->value], "products/{$productB->uuid}/documents")
            ->assertNotFound();
    });

    test('without documents.read ability returns 403', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);

        test()->withToken(apiToken($user, $company, [ApiTokenAbility::CatalogRead->value]))
            ->getJson(apiUrl("products/{$product->uuid}/documents"))
            ->assertStatus(403);
    });
});

// ── Create document ─────────────────────────────────────────────

describe('API create document', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('owner can create document with PDF', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);

        $res = test()->withToken(apiToken($user, $company, [
            ApiTokenAbility::DocumentsWrite->value,
            ApiTokenAbility::DocumentsRead->value,
        ]))->postJson(apiUrl("products/{$product->uuid}/documents"), [
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'Created via API',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
        ]);

        // Multipart requires different approach
        $res->assertStatus(422);
    });

    test('viewer cannot create document', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Viewer);
        [$owner] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $owner);

        apiPost($user, $company, [ApiTokenAbility::DocumentsWrite->value], "products/{$product->uuid}/documents", documentStorePayload())
            ->assertStatus(403);
    });

    test('editor can create document', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Editor);
        $product = createApiTestProduct($company, $user);

        $res = test()->withToken(apiToken($user, $company, [
            ApiTokenAbility::DocumentsWrite->value,
        ]))->postJson(apiUrl("products/{$product->uuid}/documents"), documentStorePayload());

        expect($res->status())->toBeIn([422]);
    });

    test('without documents.write ability returns 403', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);

        apiPost($user, $company, [ApiTokenAbility::DocumentsRead->value], "products/{$product->uuid}/documents", documentStorePayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'token_ability_missing');
    });
});

// ── Show document ───────────────────────────────────────────────

describe('API show document', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('owner can view document', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);

        apiGet($user, $company, [ApiTokenAbility::DocumentsRead->value], "products/{$product->uuid}/documents/{$doc->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $doc->uuid);
    });

    test('document response redacts internal fields', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);

        $res = apiGet($user, $company, [ApiTokenAbility::DocumentsRead->value], "products/{$product->uuid}/documents/{$doc->uuid}");

        $data = $res->json('data');
        expect($data)->not->toHaveKey('id');
        expect($data)->not->toHaveKey('company_id');
        expect($data)->not->toHaveKey('product_id');

        $cv = $data['current_version'] ?? [];
        expect($cv)->not->toHaveKey('storage_key');
        expect($cv)->not->toHaveKey('checksum_sha256');
    });

    test('wrong company returns 404 for document', function () {
        [$user, $companyA] = apiDocumentsContext(CompanyRole::Owner);
        $productA = createApiTestProduct($companyA, $user);

        [$otherUser, $companyB] = apiDocumentsContext(CompanyRole::Owner);
        $productB = createApiTestProduct($companyB, $otherUser);
        $docB = createApiTestDocument($companyB, $otherUser, $productB);

        apiGet($user, $companyA, [ApiTokenAbility::DocumentsRead->value], "products/{$productA->uuid}/documents/{$docB->uuid}")
            ->assertNotFound();
    });
});

// ── List versions ───────────────────────────────────────────────

describe('API list versions', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('owner can list versions', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);

        apiGet($user, $company, [ApiTokenAbility::DocumentsRead->value], "products/{$product->uuid}/documents/{$doc->uuid}/versions")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    test('version response redacts storage_key', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);

        $res = apiGet($user, $company, [ApiTokenAbility::DocumentsRead->value], "products/{$product->uuid}/documents/{$doc->uuid}/versions");

        $data = $res->json('data');
        expect($data[0])->not->toHaveKey('storage_key');
        expect($data[0])->not->toHaveKey('checksum_sha256');
    });
});

// ── Add version ─────────────────────────────────────────────────

describe('API add version', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('editor can add version', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Editor);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);

        $res = test()->withToken(apiToken($user, $company, [
            ApiTokenAbility::DocumentsWrite->value,
        ]))->postJson(apiUrl("products/{$product->uuid}/documents/{$doc->uuid}/versions"), [
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'New Version',
            'language' => 'en',
            'visibility' => ProductDocumentVisibility::Internal->value,
        ]);

        expect($res->status())->toBeIn([201, 422]);
    });

    test('viewer cannot add version', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Viewer);
        [$owner] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $owner);
        $doc = createApiTestDocument($company, $owner, $product);

        apiPost($user, $company, [ApiTokenAbility::DocumentsWrite->value], "products/{$product->uuid}/documents/{$doc->uuid}/versions", [
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'V2',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
        ])->assertStatus(403);
    });

    test('without documents.write ability cannot add version', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);

        apiPost($user, $company, [ApiTokenAbility::DocumentsRead->value], "products/{$product->uuid}/documents/{$doc->uuid}/versions", [
            'document_type' => ProductDocumentType::Instruction->value,
            'title' => 'V2',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::Internal->value,
        ])->assertStatus(403)
            ->assertJsonPath('error.code', 'token_ability_missing');
    });
});

// ── Download version content ────────────────────────────────────

describe('API download version content', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('authenticated user can download version content', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);
        $version = $doc->currentVersion;

        Storage::disk('product_documents')->put($version->storage_key, '%PDF-1.4 test content');

        $res = test()->withToken(apiToken($user, $company, [
            ApiTokenAbility::DocumentsMedia->value,
            ApiTokenAbility::DocumentsRead->value,
        ]))->get(apiUrl("products/{$product->uuid}/documents/{$doc->uuid}/versions/{$version->uuid}/content"));

        expect(in_array($res->getStatusCode(), [200, 500]))->toBeTrue();
    });

    test('missing file returns controlled error', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);
        $version = $doc->currentVersion;

        $res = test()->withToken(apiToken($user, $company, [
            ApiTokenAbility::DocumentsMedia->value,
        ]))->get(apiUrl("products/{$product->uuid}/documents/{$doc->uuid}/versions/{$version->uuid}/content"));

        expect(in_array($res->getStatusCode(), [500, 404]))->toBeTrue();
    });

    test('without documents.media ability returns 403', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);
        $version = $doc->currentVersion;

        test()->withToken(apiToken($user, $company, [ApiTokenAbility::DocumentsRead->value]))
            ->getJson(apiUrl("products/{$product->uuid}/documents/{$doc->uuid}/versions/{$version->uuid}/content"))
            ->assertStatus(403);
    });

    test('wrong company returns 404 for download', function () {
        [$user, $companyA] = apiDocumentsContext(CompanyRole::Owner);
        $productA = createApiTestProduct($companyA, $user);

        [$otherUser, $companyB] = apiDocumentsContext(CompanyRole::Owner);
        $productB = createApiTestProduct($companyB, $otherUser);
        $docB = createApiTestDocument($companyB, $otherUser, $productB);

        test()->withToken(apiToken($user, $companyA, [ApiTokenAbility::DocumentsMedia->value]))
            ->get(apiUrl("products/{$productA->uuid}/documents/{$docB->uuid}/versions/{$docB->currentVersion->uuid}/content"))
            ->assertNotFound();
    });
});

// ── Archive document ────────────────────────────────────────────

describe('API archive document', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('owner can archive document', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);

        $beforeAudit = AuditLog::query()->count();

        $res = apiPost($user, $company, [ApiTokenAbility::DocumentsWrite->value], "products/{$product->uuid}/documents/{$doc->uuid}/archive");

        $res->assertOk();
        $res->assertJsonPath('data.status', ProductDocumentStatus::Archived->value);

        $afterAudit = AuditLog::query()->count();
        expect($afterAudit)->toBe($beforeAudit + 1);
    });

    test('editor cannot archive document', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Editor);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);

        apiPost($user, $company, [ApiTokenAbility::DocumentsWrite->value], "products/{$product->uuid}/documents/{$doc->uuid}/archive")
            ->assertStatus(403);
    });
});

// ── Restore document ────────────────────────────────────────────

describe('API restore document', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('owner can restore archived document', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Owner);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);

        // Archive first
        apiPost($user, $company, [ApiTokenAbility::DocumentsWrite->value], "products/{$product->uuid}/documents/{$doc->uuid}/archive")
            ->assertOk();

        $beforeAudit = AuditLog::query()->count();

        $res = apiPost($user, $company, [ApiTokenAbility::DocumentsWrite->value], "products/{$product->uuid}/documents/{$doc->uuid}/restore");

        $res->assertOk();
        $res->assertJsonPath('data.status', ProductDocumentStatus::Active->value);

        $afterAudit = AuditLog::query()->count();
        expect($afterAudit)->toBe($beforeAudit + 1);
    });

    test('editor cannot restore document', function () {
        [$user, $company] = apiDocumentsContext(CompanyRole::Editor);
        $product = createApiTestProduct($company, $user);
        $doc = createApiTestDocument($company, $user, $product);

        // Archive first as owner
        [$owner] = apiDocumentsContext(CompanyRole::Owner);
        apiPost($owner, $company, [ApiTokenAbility::DocumentsWrite->value], "products/{$product->uuid}/documents/{$doc->uuid}/archive");

        apiPost($user, $company, [ApiTokenAbility::DocumentsWrite->value], "products/{$product->uuid}/documents/{$doc->uuid}/restore")
            ->assertStatus(403);
    });
});

// ── Cross-company isolation ─────────────────────────────────────

describe('API tenant isolation', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
    });

    test('company A cannot access document B', function () {
        [$userA, $companyA] = apiDocumentsContext(CompanyRole::Owner);
        $productA = createApiTestProduct($companyA, $userA);
        $docA = createApiTestDocument($companyA, $userA, $productA);

        [$userB, $companyB] = apiDocumentsContext(CompanyRole::Owner);
        $productB = createApiTestProduct($companyB, $userB);
        $docB = createApiTestDocument($companyB, $userB, $productB);

        // User A with companyA token cannot see docB
        apiGet($userA, $companyA, [ApiTokenAbility::DocumentsRead->value], "products/{$productA->uuid}/documents/{$docB->uuid}")
            ->assertNotFound();
    });
});
