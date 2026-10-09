<?php

namespace App\Authorization;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\Billing\CommercialAccessPolicy;
use App\Tenancy\Contracts\CurrentCompany;
use App\Tenancy\CurrentMembership;
use App\Tenancy\TokenCurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

class CompanyAuthorizer
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly CurrentMembership $currentMembership,
        private readonly CompanyPermissionMatrix $permissionMatrix,
        private readonly TokenCurrentCompany $tokenCurrentCompany,
        private readonly ApiTokenMutationAuthorizer $apiTokenAuthorizer,
    ) {}

    public function allows(User $user, Company $company, CompanyPermission $permission): bool
    {
        if ($user->getAttribute('status') !== UserStatus::Active) {
            return false;
        }

        if (! $this->isCurrentCompany($company)) {
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
        $this->authorizeWithCompanyLock($user, $company, $permission, false);
    }

    public function authorizeCatalogWrite(User $user, Company $company, CompanyPermission $permission): void
    {
        if (! in_array($permission, [CompanyPermission::CatalogCreate, CompanyPermission::CatalogUpdate], true)) {
            throw new LogicException('Shared Company authorization is limited to catalogue create/update.');
        }
        $this->authorizeWithCompanyLock($user, $company, $permission, true);
    }

    private function authorizeWithCompanyLock(User $user, Company $company, CompanyPermission $permission, bool $sharedCompany): void
    {
        if (DB::transactionLevel() > 0) {
            $this->apiTokenAuthorizer->authorizeIfBound($user, $company, $sharedCompany);
        }
        $allowed = DB::transactionLevel() === 0
            ? $this->allows($user, $company, $permission)
            : $this->allowsMutation($user, $company, $permission, $sharedCompany);

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    private function allowsMutation(User $user, Company $company, CompanyPermission $permission, bool $sharedCompany): bool
    {
        if (! $this->isCurrentCompany($company)) {
            return false;
        }

        // Current reads bypass an earlier repeatable-read snapshot. Keep the
        // tenant lock first, before actor/membership and resource row locks.
        // Shared catalogue paths never upgrade Company to update its counter.
        $companies = Company::query()->whereKey($company->getKey());
        $currentCompany = ($sharedCompany ? $companies->sharedLock() : $companies->lockForUpdate())->first(['id', 'status', 'commercial_model_version']);
        if ($currentCompany?->status !== CompanyStatus::Active) {
            return false;
        }

        $currentUser = User::query()->whereKey($user->getKey())->sharedLock()->first(['id', 'status']);
        if ($currentUser?->status !== UserStatus::Active) {
            return false;
        }

        $role = $this->currentMembership->getForAuthorization($currentUser, $currentCompany)?->role;

        if (! $role instanceof CompanyRole || ! $this->permissionMatrix->allows($role, $permission)) {
            return false;
        }
        app(CommercialAccessPolicy::class)->assertPermission($currentCompany, $permission);

        return true;
    }

    private function isCurrentCompany(Company $company): bool
    {
        $currentCompany = $this->currentCompany->get() ?? $this->tokenCurrentCompany->get();

        return $currentCompany !== null && $currentCompany->is($company);
    }
}
