<?php

namespace App\Models\Catalog\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

trait HasCompanyScope
{
    public function scopeForCompany(Builder $query, Company|string $company): Builder
    {
        if ($company instanceof Company) {
            return $query->where($query->qualifyColumn('company_id'), $company->getKey());
        }

        if (ctype_digit($company) && (int) $company > 0) {
            return $query->where($query->qualifyColumn('company_id'), (int) $company);
        }

        return $query->whereIn(
            $query->qualifyColumn('company_id'),
            Company::query()->select('id')->where('uuid', $company),
        );
    }
}
