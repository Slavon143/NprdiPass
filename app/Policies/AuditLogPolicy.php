<?php

namespace App\Policies;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Models\AuditLog;
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

    public function view(User $user, AuditLog $auditLog, Company $company): bool
    {
        if ($company->status !== CompanyStatus::Active) {
            return false;
        }

        if ((int) $auditLog->getAttribute('company_id') !== (int) $company->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $company, CompanyPermission::AuditView);
    }
}
