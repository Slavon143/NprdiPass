<?php

namespace App\Policies\Catalog;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class CatalogPolicy
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
    ) {}

    protected function allowsCompany(
        User $user,
        Company $company,
        CompanyPermission $permission,
    ): bool {
        $freshCompany = Company::query()->find($company->getKey());

        return $freshCompany?->status === CompanyStatus::Active
            && $this->authorizer->allows($user, $freshCompany, $permission);
    }

    protected function allowsModel(
        User $user,
        Model $model,
        CompanyPermission $permission,
    ): bool {
        $freshModel = $this->freshModel($model);

        if ($freshModel === null) {
            return false;
        }

        $companyId = $freshModel->getAttribute('company_id');
        $company = is_int($companyId) ? Company::query()->find($companyId) : null;

        return $company !== null && $this->allowsCompany($user, $company, $permission);
    }

    protected function freshModel(Model $model): ?Model
    {
        $key = $model->getKey();

        return $key === null ? null : $model->newQuery()->find($key);
    }
}
