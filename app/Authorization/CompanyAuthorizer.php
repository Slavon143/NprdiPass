<?php

namespace App\Authorization;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\CurrentMembership;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;

class CompanyAuthorizer
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly CurrentMembership $currentMembership,
        private readonly CompanyPermissionMatrix $permissionMatrix,
        private readonly TokenCurrentCompany $tokenCurrentCompany,
    ) {}

    public function allows(User $user, Company $company, CompanyPermission $permission): bool
    {
        if ($user->getAttribute('status') !== UserStatus::Active) {
            return false;
        }

        $currentCompany = $this->currentCompany->get();

        if ($currentCompany === null) {
            $currentCompany = $this->tokenCurrentCompany->get();
        }

        if ($currentCompany === null || ! $currentCompany->is($company)) {
            return false;
        }

        $membership = $this->currentMembership->get($user, $company);

        if ($membership === null) {
            // Platform roles never create an implicit tenant membership or bypass.
            if ($user->hasRole(PlatformRole::SuperAdmin->value)) {
                return false;
            }

            return false;
        }

        $role = $membership->getAttribute('role');

        return $role instanceof CompanyRole
            && $this->permissionMatrix->allows($role, $permission);
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(User $user, Company $company, CompanyPermission $permission): void
    {
        if (! $this->allows($user, $company, $permission)) {
            throw new AuthorizationException;
        }
    }
}
