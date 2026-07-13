<?php

namespace App\Authorization;

use App\Enums\CompanyPermission;
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

    private function allows(User $user, Company $company, CompanyPermission $permission): bool
    {
        return $this->authorizer->allows($user, $company, $permission);
    }
}
