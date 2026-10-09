<?php

namespace App\Services\Billing;

use App\Enums\CompanyPermission;
use App\Exceptions\Billing\CommercialReadOnlyException;
use App\Models\Billing\CompanySubscription;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CommercialAccessPolicy
{
    private const WRITES = [
        CompanyPermission::MembersInvite, CompanyPermission::MembersUpdateRole,
        CompanyPermission::ApiTokensCreate, CompanyPermission::CatalogCreate,
        CompanyPermission::CatalogUpdate, CompanyPermission::CatalogArchive,
        CompanyPermission::CatalogPublish, CompanyPermission::CatalogManageCategories,
        CompanyPermission::CatalogManageAttributes, CompanyPermission::CatalogManageMedia,
        CompanyPermission::CatalogManageDocuments, CompanyPermission::CatalogArchiveDocuments,
        CompanyPermission::CatalogSubmitDocumentReview, CompanyPermission::CatalogReviewDocuments,
        CompanyPermission::CatalogApproveDocuments, CompanyPermission::CatalogRejectDocuments,
        CompanyPermission::PassportsManage, CompanyPermission::PassportsPublish,
        CompanyPermission::FortnoxManage,
    ];

    /** @phpstan-impure */
    public function isReadOnly(Company $company): bool
    {
        if ($company->commercial_model_version !== 1) {
            return false;
        }
        $subscription = CompanySubscription::query()->forCompany($company)->first();

        return ! $subscription instanceof CompanySubscription || ! $subscription->grantsEntitlements();
    }

    public function assertPermission(Company $currentCompany, CompanyPermission $permission): void
    {
        if (! in_array($permission, self::WRITES, true) || $currentCompany->commercial_model_version !== 1) {
            return;
        }
        $feature = match ($permission) {
            CompanyPermission::ApiTokensCreate => 'api.enabled',
            CompanyPermission::FortnoxManage => 'fortnox.enabled',
            default => null,
        };
        if ($feature !== null) {
            $this->assertFeature($currentCompany, $feature);
        } else {
            $this->writableSubscription($currentCompany);
        }
    }

    public function assertWritable(Company $currentCompany): void
    {
        if ($currentCompany->commercial_model_version === 1) {
            $this->writableSubscription($currentCompany);
        }
    }

    public function assertFeature(Company $currentCompany, string $feature): void
    {
        if ($currentCompany->commercial_model_version !== 1) {
            return;
        }
        $this->writableSubscription($currentCompany);
        if (app(EntitlementService::class)->valueForMutation($currentCompany, $feature, sharedCompany: true) !== true) {
            throw ValidationException::withMessages(['entitlement' => 'The '.$feature.' feature is not included in this subscription.']);
        }
    }

    private function writableSubscription(Company $currentCompany): CompanySubscription
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Commercial write admission requires the tenant mutation transaction.');
        }
        $subscription = CompanySubscription::query()->forCompany($currentCompany)->sharedLock()->first();
        if (! $subscription instanceof CompanySubscription || ! $subscription->grantsEntitlements()) {
            throw new CommercialReadOnlyException;
        }

        return $subscription;
    }
}
