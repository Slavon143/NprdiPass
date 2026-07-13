<?php

namespace App\Actions\Companies;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UpdateCompany
{
    private const ALLOWED_FIELDS = [
        'name',
        'legal_name',
        'organization_number',
        'country_code',
        'billing_email',
    ];

    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $actor, Company $company, array $data): Company
    {
        if ($company->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize(
            $actor,
            $company,
            CompanyPermission::CompanyUpdate,
        );

        $company->fill(array_intersect_key($data, array_flip(self::ALLOWED_FIELDS)));
        $company->save();

        return $company->refresh();
    }
}
