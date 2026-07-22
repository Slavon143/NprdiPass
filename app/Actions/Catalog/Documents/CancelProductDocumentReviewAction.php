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

class CancelProductDocumentReviewAction extends SubmitProductDocumentVersionForReviewAction
{
    public function execute(User $actor, Company $company, ProductDocument $document, ProductDocumentVersion $version, ?string $comment = null): ProductDocumentVersion
    {
        $this->authorize($actor, $company, CompanyPermission::CatalogSubmitDocumentReview);
        $this->assertDocumentVersion($company, $document, $version);

        return DB::transaction(function () use ($actor, $company, $document, $version, $comment): ProductDocumentVersion {
            $lockedVersion = ProductDocumentVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedVersion->review_status !== ProductDocumentReviewStatus::PendingReview) {
                throw new \RuntimeException('Only pending-review versions can be cancelled.');
            }

            $previousReview = $lockedVersion->review_status->value;
            $previousApproval = $lockedVersion->approval_status->value;

            $lockedVersion->forceFill([
                'review_status' => ProductDocumentReviewStatus::Cancelled->value,
                'approval_status' => ProductDocumentApprovalStatus::Pending->value,
                'review_comment' => $comment,
            ])->save();

            $this->recordDecision($company, $document, $lockedVersion, $actor, 'cancelled', $previousReview, $previousApproval, ProductDocumentReviewStatus::Cancelled->value, ProductDocumentApprovalStatus::Pending->value, $comment);

            $this->auditLogger->logTenant($company, AuditEvent::CatalogDocumentReviewCancelled, $actor, $document, [
                'document_uuid' => $document->uuid,
                'version_uuid' => $lockedVersion->uuid,
                'previous_review_status' => $previousReview,
                'new_review_status' => ProductDocumentReviewStatus::Cancelled->value,
            ]);

            return $lockedVersion->refresh();
        });
    }
}
