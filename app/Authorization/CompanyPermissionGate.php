<?php

namespace App\Authorization;

use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;

class CompanyPermissionGate
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
    ) {}

    public function companyView(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::CompanyView);
    }

    public function companyUpdate(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::CompanyUpdate);
    }

    public function membersView(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::MembersView);
    }

    public function membersInvite(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::MembersInvite);
    }

    public function membersUpdateRole(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::MembersUpdateRole);
    }

    public function membersRemove(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::MembersRemove);
    }

    public function auditView(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::AuditView);
    }

    public function apiTokensView(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::ApiTokensView);
    }

    public function apiTokensCreate(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::ApiTokensCreate);
    }

    public function apiTokensRevoke(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::ApiTokensRevoke);
    }

    public function catalogView(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogView);
    }

    public function catalogCreate(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogCreate);
    }

    public function catalogUpdate(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogUpdate);
    }

    public function catalogArchive(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogArchive);
    }

    public function catalogPublish(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogPublish);
    }

    public function catalogManageCategories(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogManageCategories);
    }

    public function catalogManageAttributes(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogManageAttributes);
    }

    public function catalogManageMedia(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogManageMedia);
    }

    public function catalogViewDocuments(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogViewDocuments);
    }

    public function catalogManageDocuments(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogManageDocuments);
    }

    public function catalogArchiveDocuments(User $user, Company $company): bool
    {
        return $this->allowsCatalog($user, $company, CompanyPermission::CatalogArchiveDocuments);
    }

    public function passportsView(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::PassportsView);
    }

    public function passportsManage(User $user, Company $company): bool
    {
        return $this->allows($user, $company, CompanyPermission::PassportsManage);
    }

    private function allowsCatalog(User $user, Company $company, CompanyPermission $permission): bool
    {
        $freshCompany = Company::query()->find($company->getKey());

        return $freshCompany?->status === CompanyStatus::Active
            && $this->allows($user, $freshCompany, $permission);
    }

    private function allows(User $user, Company $company, CompanyPermission $permission): bool
    {
        return $this->authorizer->allows($user, $company, $permission);
    }
}
