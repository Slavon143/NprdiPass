<?php

namespace App\Actions\Approvals;

use App\Actions\Passports\PublishProductPassport;
use App\Audit\AuditLogger;
use App\Authorization\CompanyAuthorizer;
use App\Data\Passports\PublicationResult;
use App\Enums\Approvals\PassportPublicationApprovalStatus;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Models\Approvals\PassportPublicationApproval;
use App\Models\Catalog\Product;
use App\Models\Company;
use App\Models\Passports\ProductPassport;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ApprovePassportPublicationApprovalAction
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly PublishProductPassport $publishProductPassport,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(
        User $actor,
        Company $company,
        Product $product,
        ProductPassport $passport,
        PassportPublicationApproval $approval,
    ): PublicationResult {
        $this->authorizer->authorize($actor, $company, CompanyPermission::PassportsPublish);
        $this->assertApprovalBelongsToPassport($company, $product, $passport, $approval);

        if ($approval->status !== PassportPublicationApprovalStatus::Pending) {
            throw new ConflictHttpException('This publication approval is no longer pending.');
        }

        if ((int) $approval->requested_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages([
                'approval' => ['Publication must be approved by a different company member.'],
            ]);
        }

        $result = $this->publishProductPassport->handle(
            $actor,
            $company,
            $product,
            $passport,
            $approval->expected_revision,
            $approval->acknowledge_warnings,
        );

        $approval->forceFill([
            'status' => PassportPublicationApprovalStatus::Approved,
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
            'published_version_id' => $result->publishedVersion->getKey(),
        ])->save();

        $this->auditLogger->logTenant(
            $company,
            AuditEvent::PassportPublicationApprovalApproved,
            $actor,
            $approval,
            [
                'product_uuid' => $product->uuid,
                'passport_uuid' => $passport->uuid,
                'approval_uuid' => $approval->uuid,
                'published_version_uuid' => $result->publishedVersion->uuid,
                'requested_by' => $approval->requested_by,
            ],
        );

        return $result;
    }

    private function assertApprovalBelongsToPassport(
        Company $company,
        Product $product,
        ProductPassport $passport,
        PassportPublicationApproval $approval,
    ): void {
        if ((int) $approval->company_id !== (int) $company->getKey()
            || (int) $approval->product_id !== (int) $product->getKey()
            || (int) $approval->passport_id !== (int) $passport->getKey()) {
            throw new NotFoundHttpException;
        }
    }
}
