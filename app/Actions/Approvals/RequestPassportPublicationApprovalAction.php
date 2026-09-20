<?php

namespace App\Actions\Approvals;

use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Enums\Approvals\PassportPublicationApprovalStatus;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Models\Approvals\PassportPublicationApproval;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RequestPassportPublicationApprovalAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(
        User $actor,
        Company $company,
        Product $product,
        ProductPassport $passport,
        int $expectedRevision,
        bool $acknowledgeWarnings,
        ?string $requestNote = null,
    ): PassportPublicationApproval {
        return DB::transaction(function () use ($actor, $company, $product, $passport, $expectedRevision, $acknowledgeWarnings, $requestNote): PassportPublicationApproval {
            $this->authorizer->authorize($actor, $company, CompanyPermission::PassportsManage);
            $this->assertProductBelongsToCompany($company, $product);
            $this->assertPassportBelongsToProduct($passport, $product);

            $lockedPassport = ProductPassport::query()
                ->whereKey($passport->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $draft = $lockedPassport->currentDraftVersion;

            if ($draft === null || $draft->draft_revision !== $expectedRevision) {
                throw new ConflictHttpException('The passport draft revision changed. Reload before requesting approval.');
            }

            $approval = PassportPublicationApproval::query()
                ->where('passport_id', $lockedPassport->getKey())
                ->where('draft_version_id', $draft->getKey())
                ->pending()
                ->lockForUpdate()
                ->first();

            if (! $approval instanceof PassportPublicationApproval) {
                $approval = new PassportPublicationApproval;
                $approval->setAttribute('company_id', $company->getKey());
                $approval->setAttribute('product_id', $product->getKey());
                $approval->setAttribute('passport_id', $lockedPassport->getKey());
                $approval->setAttribute('draft_version_id', $draft->getKey());
                $approval->setAttribute('status', PassportPublicationApprovalStatus::Pending);
                $approval->setAttribute('requested_at', now());
            }

            $approval->setAttribute('expected_revision', $expectedRevision);
            $approval->setAttribute('acknowledge_warnings', $acknowledgeWarnings);
            $approval->setAttribute('requested_by', $actor->getKey());
            $approval->setAttribute('request_note', $requestNote);
            $approval->save();

            $this->auditLogger->logTenant(
                $company,
                AuditEvent::PassportPublicationApprovalRequested,
                $actor,
                $approval,
                [
                    'product_uuid' => $product->uuid,
                    'passport_uuid' => $lockedPassport->uuid,
                    'draft_version_uuid' => $draft->uuid,
                    'expected_revision' => $expectedRevision,
                ],
            );

            return $approval;
        });
    }

    private function assertProductBelongsToCompany(Company $company, Product $product): void
    {
        if ((int) $product->getAttribute('company_id') !== (int) $company->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    private function assertPassportBelongsToProduct(ProductPassport $passport, Product $product): void
    {
        if ((int) $passport->getAttribute('product_id') !== (int) $product->getKey()) {
            throw new NotFoundHttpException;
        }
    }
}
