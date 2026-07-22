<?php

use App\Actions\Catalog\Documents\ApproveProductDocumentVersionAction;
use App\Actions\Catalog\Documents\CreateProductDocumentAction;
use App\Actions\Catalog\Documents\RejectProductDocumentVersionAction;
use App\Actions\Catalog\Documents\SubmitProductDocumentVersionForReviewAction;
use App\Enums\AuditEvent;
use App\Enums\Catalog\ProductStatus;
use App\Enums\CompanyRole;
use App\Enums\Documents\ProductDocumentApprovalStatus;
use App\Enums\Documents\ProductDocumentExpiryState;
use App\Enums\Documents\ProductDocumentReviewStatus;
use App\Enums\Documents\ProductDocumentType;
use App\Enums\Documents\ProductDocumentVisibility;
use App\Models\AuditLog;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentReviewDecision;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Catalog\Documents\ProductDocumentCurrentVersionResolver;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function workflowUser(Company $company, CompanyRole $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    CompanyMembership::factory()->create([
        'company_id' => $company->getKey(),
        'user_id' => $user->getKey(),
        'role' => $role,
    ]);

    return $user;
}

function workflowProduct(Company $company, User $actor): Product
{
    return Product::query()->forceCreate([
        'uuid' => fake()->uuid(),
        'company_id' => $company->getKey(),
        'name' => 'Workflow Product '.fake()->word(),
        'slug' => 'workflow-product-'.fake()->unique()->slug(1),
        'slug_normalized' => fake()->unique()->slug(1),
        'status' => ProductStatus::Active->value,
        'created_by' => $actor->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function workflowPdf(string $name = 'workflow.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n%%EOF");
}

describe('R3.4 document workflow', function () {
    beforeEach(function () {
        Storage::fake('product_documents');
        config()->set('documents.creator_self_approval_allowed', false);
        config()->set('documents.auto_approve_new_versions', false);
    });

    test('document version can be submitted and approved with audit and decision trail', function () {
        $company = Company::factory()->create();
        $creator = workflowUser($company, CompanyRole::Editor);
        $reviewer = workflowUser($company, CompanyRole::Owner);
        $product = workflowProduct($company, $creator);

        test()->actingAs($creator);
        app(CurrentCompany::class)->set($company);

        $document = app(CreateProductDocumentAction::class)->execute($creator, $company, $product, [
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'CE Certificate',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'issuer_name' => 'Issuer AB',
            'certificate_number' => ' cert-001 ',
            'issue_date' => now()->subDay()->toDateString(),
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ], workflowPdf());

        $version = $document->currentVersion;
        expect($version->review_status)->toBe(ProductDocumentReviewStatus::Draft);
        expect($version->approval_status)->toBe(ProductDocumentApprovalStatus::Pending);

        $submitted = app(SubmitProductDocumentVersionForReviewAction::class)
            ->execute($creator, $company, $document, $version, 'Ready for review');

        expect($submitted->review_status)->toBe(ProductDocumentReviewStatus::PendingReview);

        test()->actingAs($reviewer);
        $approved = app(ApproveProductDocumentVersionAction::class)
            ->execute($reviewer, $company, $document, $submitted, 'Approved for Passport');

        expect($approved->review_status)->toBe(ProductDocumentReviewStatus::Approved);
        expect($approved->approval_status)->toBe(ProductDocumentApprovalStatus::Approved);
        expect(ProductDocumentReviewDecision::query()->where('version_id', $version->getKey())->count())->toBe(2);
        expect(AuditLog::query()->where('event', AuditEvent::CatalogDocumentApproved->value)->exists())->toBeTrue();
    });

    test('creator self approval is blocked by default', function () {
        $company = Company::factory()->create();
        $creator = workflowUser($company, CompanyRole::Owner);
        $product = workflowProduct($company, $creator);

        test()->actingAs($creator);
        app(CurrentCompany::class)->set($company);

        $document = app(CreateProductDocumentAction::class)->execute($creator, $company, $product, [
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'Self Approval Certificate',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'issuer_name' => 'Issuer AB',
            'issue_date' => now()->subDay()->toDateString(),
        ], workflowPdf());

        $submitted = app(SubmitProductDocumentVersionForReviewAction::class)
            ->execute($creator, $company, $document, $document->currentVersion);

        expect(fn () => app(ApproveProductDocumentVersionAction::class)
            ->execute($creator, $company, $document, $submitted))
            ->toThrow(RuntimeException::class, 'different user');
    });

    test('reject requires reason and records rejected status', function () {
        $company = Company::factory()->create();
        $creator = workflowUser($company, CompanyRole::Editor);
        $reviewer = workflowUser($company, CompanyRole::Owner);
        $product = workflowProduct($company, $creator);

        test()->actingAs($creator);
        app(CurrentCompany::class)->set($company);

        $document = app(CreateProductDocumentAction::class)->execute($creator, $company, $product, [
            'document_type' => ProductDocumentType::Certificate->value,
            'title' => 'Rejectable Certificate',
            'language' => 'sv',
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'issuer_name' => 'Issuer AB',
            'issue_date' => now()->subDay()->toDateString(),
        ], workflowPdf());

        $submitted = app(SubmitProductDocumentVersionForReviewAction::class)
            ->execute($creator, $company, $document, $document->currentVersion);

        expect(fn () => app(RejectProductDocumentVersionAction::class)
            ->execute($reviewer, $company, $document, $submitted, ''))
            ->toThrow(InvalidArgumentException::class);

        $rejected = app(RejectProductDocumentVersionAction::class)
            ->execute($reviewer, $company, $document, $submitted, 'Missing certificate number');

        expect($rejected->review_status)->toBe(ProductDocumentReviewStatus::Rejected);
        expect($rejected->approval_status)->toBe(ProductDocumentApprovalStatus::Rejected);
        expect($rejected->rejection_reason)->toBe('Missing certificate number');
    });

    test('resolver selects latest approved public valid version and skips rejected or expired candidates', function () {
        $company = Company::factory()->create();
        $actor = workflowUser($company, CompanyRole::Owner);
        $product = workflowProduct($company, $actor);

        $document = ProductDocument::factory()->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'created_by_user_id' => $actor->getKey(),
        ]);

        $approvedV1 = ProductDocumentVersion::factory()->forDocument($document)->create([
            'version_number' => 1,
            'document_type' => ProductDocumentType::Certificate->value,
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'review_status' => ProductDocumentReviewStatus::Approved->value,
            'approval_status' => ProductDocumentApprovalStatus::Approved->value,
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
            'file_available' => true,
            'created_by_user_id' => $actor->getKey(),
        ]);

        ProductDocumentVersion::factory()->forDocument($document)->create([
            'version_number' => 2,
            'document_type' => ProductDocumentType::Certificate->value,
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'review_status' => ProductDocumentReviewStatus::Rejected->value,
            'approval_status' => ProductDocumentApprovalStatus::Rejected->value,
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
            'created_by_user_id' => $actor->getKey(),
        ]);

        ProductDocumentVersion::factory()->forDocument($document)->create([
            'version_number' => 3,
            'document_type' => ProductDocumentType::Certificate->value,
            'visibility' => ProductDocumentVisibility::PassportPublic->value,
            'review_status' => ProductDocumentReviewStatus::Approved->value,
            'approval_status' => ProductDocumentApprovalStatus::Approved->value,
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->subDay()->toDateString(),
            'created_by_user_id' => $actor->getKey(),
        ]);

        $document->forceFill(['current_version_id' => $approvedV1->getKey()])->save();

        $resolved = app(ProductDocumentCurrentVersionResolver::class)->resolve($document, true);

        expect($resolved?->uuid)->toBe($approvedV1->uuid);
        expect($approvedV1->expiryState())->toBe(ProductDocumentExpiryState::Valid);
    });
});
