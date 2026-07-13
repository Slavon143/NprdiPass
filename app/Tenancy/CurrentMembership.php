<?php

namespace App\Tenancy;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;

class CurrentMembership
{
    public function get(User $user, Company $company): ?CompanyMembership
    {
        return CompanyMembership::query()
            ->where('user_id', $user->getKey())
            ->where('company_id', $company->getKey())
            ->first();
    }
}
