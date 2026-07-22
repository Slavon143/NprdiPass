<?php

namespace App\Actions\Catalog\Documents;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Documents\ProductDocumentApprovalStatus;
use App\Enums\Documents\ProductDocumentReviewStatus;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentReviewDecision;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SubmitProductDocumentVersionForReviewAction extends DocumentAction
{
    public function __construct(CompanyAuthorizer $authorizer, AuditLogger $auditLogger)
    {
        parent::__construct($authorizer, $auditLogger);
    }

    public function execute(User $actor, Company $company, ProductDocument $document, ProductDocumentVersion $version, ?string $comment = null): ProductDocumentVersion
    {
        $this->authorize($actor, $company, CompanyPermission::CatalogSubmitDocumentReview);
        $this->assertDocumentVersion($company, $document, $version);

        return DB::transaction(function () use ($actor, $company, $document, $version, $comment): ProductDocumentVersion {
            $lockedVersion = ProductDocumentVersion::query()
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedVersion->review_status, [
                ProductDocumentReviewStatus::Draft,
                ProductDocumentReviewStatus::Rejected,
                ProductDocumentReviewStatus::Cancelled,
            ], true)) {
                throw new \RuntimeException('Only draft, rejected or cancelled versions can be submitted for review.');
            }

            $previousReview = $lockedVersion->review_status->value;
            $previousApproval = $lockedVersion->approval_status->value;

            $lockedVersion->forceFill([
                'review_status' => ProductDocumentReviewStatus::PendingReview->value,
                'approval_status' => ProductDocumentApprovalStatus::Pending->value,
                'submitted_at' => now(),
                'submitted_by_user_id' => $actor->getKey(),
                'review_comment' => $comment,
                'rejection_reason' => null,
            ])->save();

            $this->recordDecision($company, $document, $lockedVersion, $actor, 'submitted', $previousReview, $previousApproval, ProductDocumentReviewStatus::PendingReview->value, ProductDocumentApprovalStatus::Pending->value, $comment);

            $this->auditLogger->logTenant($company, AuditEvent::CatalogDocumentReviewSubmitted, $actor, $document, [
                'document_uuid' => $document->uuid,
                'version_uuid' => $lockedVersion->uuid,
                'previous_review_status' => $previousReview,
                'new_review_status' => ProductDocumentReviewStatus::PendingReview->value,
            ]);

            return $lockedVersion->refresh();
        });
    }

    protected function assertDocumentVersion(Company $company, ProductDocument $document, ProductDocumentVersion $version): void
    {
        if ((int) $document->company_id !== (int) $company->getKey()
            || (int) $version->company_id !== (int) $company->getKey()
            || (int) $version->document_id !== (int) $document->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    protected function recordDecision(
        Company $company,
        ProductDocument $document,
        ProductDocumentVersion $version,
        User $actor,
        string $decision,
        ?string $previousReview,
        ?string $previousApproval,
        string $newReview,
        string $newApproval,
        ?string $comment,
    ): void {
        ProductDocumentReviewDecision::query()->create([
            'uuid' => Str::uuid()->toString(),
            'company_id' => $company->getKey(),
            'document_id' => $document->getKey(),
            'version_id' => $version->getKey(),
            'actor_id' => $actor->getKey(),
            'decision' => $decision,
            'previous_review_status' => $previousReview,
            'new_review_status' => $newReview,
            'previous_approval_status' => $previousApproval,
            'new_approval_status' => $newApproval,
            'comment' => $comment,
            'decided_at' => now(),
        ]);
    }
}
