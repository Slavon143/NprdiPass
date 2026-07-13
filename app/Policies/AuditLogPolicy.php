<?php

namespace App\Policies;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;

class AuditLogPolicy
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
    ) {}

    public function viewAny(User $user, Company $company): bool
    {
        return $company->status === CompanyStatus::Active
            && $this->authorizer->allows($user, $company, CompanyPermission::AuditView);
    }
}
