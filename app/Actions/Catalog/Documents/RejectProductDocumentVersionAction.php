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

class RejectProductDocumentVersionAction extends SubmitProductDocumentVersionForReviewAction
{
    public function execute(User $actor, Company $company, ProductDocument $document, ProductDocumentVersion $version, ?string $reason = null): ProductDocumentVersion
    {
        $this->authorize($actor, $company, CompanyPermission::CatalogRejectDocuments);
        $this->assertDocumentVersion($company, $document, $version);

        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A rejection reason is required.');
        }

        return DB::transaction(function () use ($actor, $company, $document, $version, $reason): ProductDocumentVersion {
            $lockedVersion = ProductDocumentVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedVersion->review_status !== ProductDocumentReviewStatus::PendingReview) {
                throw new \RuntimeException('Only pending-review versions can be rejected.');
            }

            $previousReview = $lockedVersion->review_status->value;
            $previousApproval = $lockedVersion->approval_status->value;

            $lockedVersion->forceFill([
                'review_status' => ProductDocumentReviewStatus::Rejected->value,
                'approval_status' => ProductDocumentApprovalStatus::Rejected->value,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $actor->getKey(),
                'rejection_reason' => $reason,
            ])->save();

            $this->recordDecision($company, $document, $lockedVersion, $actor, 'rejected', $previousReview, $previousApproval, ProductDocumentReviewStatus::Rejected->value, ProductDocumentApprovalStatus::Rejected->value, $reason);

            $this->auditLogger->logTenant($company, AuditEvent::CatalogDocumentRejected, $actor, $document, [
                'document_uuid' => $document->uuid,
                'version_uuid' => $lockedVersion->uuid,
                'previous_review_status' => $previousReview,
                'new_review_status' => ProductDocumentReviewStatus::Rejected->value,
            ]);

            return $lockedVersion->refresh();
        });
    }
}
