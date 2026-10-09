<?php

namespace App\Tenancy;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CurrentMembership
{
    public function get(User $user, Company $company): ?CompanyMembership
    {
        return CompanyMembership::query()
            ->where('user_id', $user->getKey())
            ->where('company_id', $company->getKey())
            ->first();
    }

    public function getForAuthorization(User $user, Company $company): ?CompanyMembership
    {
        return CompanyMembership::query()
            ->where('user_id', $user->getKey())
            ->where('company_id', $company->getKey())
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())
            ->first();
    }
}
