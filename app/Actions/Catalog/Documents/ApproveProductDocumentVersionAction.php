<?php

namespace App\Actions\Catalog\Documents;

use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\Documents\ProductDocumentApprovalStatus;
use App\Enums\Documents\ProductDocumentReviewStatus;
use App\Models\Catalog\ProductDocument;
use App\Models\Catalog\ProductDocumentVersion;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApproveProductDocumentVersionAction extends SubmitProductDocumentVersionForReviewAction
{
    public function execute(User $actor, Company $company, ProductDocument $document, ProductDocumentVersion $version, ?string $comment = null): ProductDocumentVersion
    {
        $this->authorize($actor, $company, CompanyPermission::CatalogApproveDocuments);
        $this->assertDocumentVersion($company, $document, $version);

        if (! (bool) config('documents.creator_self_approval_allowed', false)
            && (int) $version->created_by_user_id === (int) $actor->getKey()) {
            throw new \RuntimeException('Document versions must be approved by a different user.');
        }

        return DB::transaction(function () use ($actor, $company, $document, $version, $comment): ProductDocumentVersion {
            $lockedDocument = ProductDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $lockedVersion = ProductDocumentVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedVersion->review_status !== ProductDocumentReviewStatus::PendingReview
                && $lockedVersion->review_status !== ProductDocumentReviewStatus::Draft) {
                throw new \RuntimeException('Only draft or pending-review versions can be approved.');
            }

            $previousReview = $lockedVersion->review_status->value;
            $previousApproval = $lockedVersion->approval_status->value;

            $lockedVersion->forceFill([
                'review_status' => ProductDocumentReviewStatus::Approved->value,
                'approval_status' => ProductDocumentApprovalStatus::Approved->value,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $actor->getKey(),
                'approved_at' => now(),
                'approved_by_user_id' => $actor->getKey(),
                'review_comment' => $comment,
                'rejection_reason' => null,
            ])->save();

            $lockedDocument->forceFill([
                'current_version_id' => $lockedVersion->getKey(),
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->recordDecision($company, $lockedDocument, $lockedVersion, $actor, 'approved', $previousReview, $previousApproval, ProductDocumentReviewStatus::Approved->value, ProductDocumentApprovalStatus::Approved->value, $comment);

            $this->auditLogger->logTenant($company, AuditEvent::CatalogDocumentApproved, $actor, $lockedDocument, [
                'document_uuid' => $lockedDocument->uuid,
                'version_uuid' => $lockedVersion->uuid,
                'previous_review_status' => $previousReview,
                'new_review_status' => ProductDocumentReviewStatus::Approved->value,
            ]);

            return $lockedVersion->refresh();
        });
    }
}
