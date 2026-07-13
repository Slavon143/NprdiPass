<?php

namespace App\Policies;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\CurrentMembership;

class CompanyMemberPolicy
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly CurrentMembership $currentMembership,
    ) {}

    public function viewAny(User $user, Company $company): bool
    {
        return $this->authorizer->allows($user, $company, CompanyPermission::MembersView);
    }

    public function view(User $user, CompanyMembership $membership): bool
    {
        $freshMembership = $this->freshMembership($membership);
        $company = $freshMembership === null ? null : $this->companyFor($freshMembership);

        return $company !== null
            && $this->authorizer->allows($user, $company, CompanyPermission::MembersView);
    }

    public function updateRole(User $user, CompanyMembership $membership): bool
    {
        $freshMembership = $this->freshMembership($membership);
        $company = $freshMembership === null ? null : $this->companyFor($freshMembership);

        if ($company === null || ! $this->authorizer->allows(
            $user,
            $company,
            CompanyPermission::MembersUpdateRole,
        )) {
            return false;
        }

        $actorMembership = $this->currentMembership->get($user, $company);
        $actorRole = $actorMembership?->getAttribute('role');
        $targetRole = $freshMembership->getAttribute('role');

        if ($actorRole === CompanyRole::Owner) {
            return true;
        }

        return $actorRole === CompanyRole::Admin
            && $targetRole instanceof CompanyRole
            && $targetRole !== CompanyRole::Owner;
    }

    public function remove(User $user, CompanyMembership $membership): bool
    {
        $freshMembership = $this->freshMembership($membership);

        if ($freshMembership === null || $freshMembership->getAttribute('user_id') === $user->getKey()) {
            return false;
        }

        $company = $this->companyFor($freshMembership);

        if ($company === null || ! $this->authorizer->allows(
            $user,
            $company,
            CompanyPermission::MembersRemove,
        )) {
            return false;
        }

        $actorMembership = $this->currentMembership->get($user, $company);
        $actorRole = $actorMembership?->getAttribute('role');
        $targetRole = $freshMembership->getAttribute('role');

        if ($actorRole === CompanyRole::Owner) {
            return true;
        }

        return $actorRole === CompanyRole::Admin
            && $targetRole instanceof CompanyRole
            && $targetRole !== CompanyRole::Owner;
    }

    private function freshMembership(CompanyMembership $membership): ?CompanyMembership
    {
        return CompanyMembership::query()->find($membership->getKey());
    }

    private function companyFor(CompanyMembership $membership): ?Company
    {
        return Company::query()->find($membership->getAttribute('company_id'));
    }
}
