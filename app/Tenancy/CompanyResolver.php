<?php

namespace App\Tenancy;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;

class CompanyResolver
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function resolveFor(User $user): ?Company
    {
        if ($user->trashed()) {
            $this->currentCompany->clear();

            return null;
        }

        $selectedCompany = $this->currentCompany->get();

        if ($selectedCompany !== null) {
            return $selectedCompany;
        }

        $activeCompanies = $user->companies()
            ->where('companies.status', CompanyStatus::Active->value)
            ->limit(2)
            ->get();

        if ($activeCompanies->count() !== 1) {
            return null;
        }

        $company = $activeCompanies->firstOrFail();

        $this->currentCompany->set($company);

        return $company;
    }
}
