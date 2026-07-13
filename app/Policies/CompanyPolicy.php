<?php

namespace App\Policies;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
    ) {}

    public function view(User $user, Company $company): bool
    {
        return $this->authorizer->allows($user, $company, CompanyPermission::CompanyView);
    }

    public function update(User $user, Company $company): bool
    {
        return $company->status === CompanyStatus::Active
            && $this->authorizer->allows($user, $company, CompanyPermission::CompanyUpdate);
    }
}
