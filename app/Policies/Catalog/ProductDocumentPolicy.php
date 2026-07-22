<?php

namespace App\Policies\Catalog;

use App\Enums\CompanyPermission;
use App\Models\Catalog\ProductDocument;
use App\Models\Company;
use App\Models\User;

class ProductDocumentPolicy extends CatalogPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $this->allowsCompany($user, $company, CompanyPermission::CatalogViewDocuments);
    }

    public function view(User $user, ProductDocument $document): bool
    {
        return $this->allowsModel($user, $document, CompanyPermission::CatalogViewDocuments);
    }

    public function create(User $user, Company $company): bool
    {
        return $this->allowsCompany($user, $company, CompanyPermission::CatalogManageDocuments);
    }

    public function addVersion(User $user, ProductDocument $document): bool
    {
        return $this->allowsModel($user, $document, CompanyPermission::CatalogManageDocuments);
    }

    public function submitReview(User $user, ProductDocument $document): bool
    {
        return $this->allowsModel($user, $document, CompanyPermission::CatalogSubmitDocumentReview)
            || $this->allowsModel($user, $document, CompanyPermission::CatalogManageDocuments);
    }

    public function review(User $user, ProductDocument $document): bool
    {
        return $this->allowsModel($user, $document, CompanyPermission::CatalogReviewDocuments)
            || $this->allowsModel($user, $document, CompanyPermission::CatalogApproveDocuments);
    }

    public function approve(User $user, ProductDocument $document): bool
    {
        return $this->allowsModel($user, $document, CompanyPermission::CatalogApproveDocuments);
    }

    public function reject(User $user, ProductDocument $document): bool
    {
        return $this->allowsModel($user, $document, CompanyPermission::CatalogRejectDocuments)
            || $this->allowsModel($user, $document, CompanyPermission::CatalogApproveDocuments);
    }

    public function archive(User $user, ProductDocument $document): bool
    {
        return $this->allowsModel($user, $document, CompanyPermission::CatalogArchiveDocuments);
    }

    public function restore(User $user, ProductDocument $document): bool
    {
        return $this->allowsModel($user, $document, CompanyPermission::CatalogArchiveDocuments);
    }

    public function download(User $user, ProductDocument $document): bool
    {
        return $this->allowsModel($user, $document, CompanyPermission::CatalogViewDocuments);
    }
}
