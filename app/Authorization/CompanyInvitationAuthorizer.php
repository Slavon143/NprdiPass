<?php

namespace App\Authorization;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Tenancy\CurrentMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CompanyInvitationAuthorizer
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly CurrentMembership $currentMembership,
    ) {}

    public function authorizeRole(User $actor, Company $company, CompanyRole $role): void
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::MembersInvite);

        $actorRole = $this->currentMembership->getForAuthorization($actor, $company)?->getAttribute('role');

        if ($role === CompanyRole::Owner && $actorRole !== CompanyRole::Owner) {
            throw new AuthorizationException;
        }
    }

    public function allowsManage(User $actor, CompanyInvitation $invitation): bool
    {
        $freshInvitation = CompanyInvitation::query()->find($invitation->getKey());

        if ($freshInvitation === null) {
            return false;
        }

        $company = Company::query()->find($freshInvitation->getAttribute('company_id'));

        if ($company === null || ! $this->authorizer->allows(
            $actor,
            $company,
            CompanyPermission::MembersInvite,
        )) {
            return false;
        }

        $invitedRole = $freshInvitation->getAttribute('role');
        $actorRole = $this->currentMembership->get($actor, $company)?->getAttribute('role');

        return $invitedRole !== CompanyRole::Owner || $actorRole === CompanyRole::Owner;
    }

    public function authorizeManage(User $actor, CompanyInvitation $invitation): void
    {
        $this->authorizeManagement($actor, $invitation, CompanyPermission::MembersInvite);
    }

    public function authorizeCancellation(User $actor, CompanyInvitation $invitation): void
    {
        $this->authorizeManagement($actor, $invitation, CompanyPermission::MembersCancelInvitation);
    }

    private function authorizeManagement(User $actor, CompanyInvitation $invitation, CompanyPermission $permission): void
    {
        $company = Company::query()->find($invitation->getAttribute('company_id'));
        if ($company === null) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $company, $permission);
        $currentInvitation = CompanyInvitation::query()
            ->where('company_id', $company->getKey())
            ->whereKey($invitation->getKey())
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())
            ->first();
        if ($currentInvitation === null || ($currentInvitation->role === CompanyRole::Owner
            && $this->currentMembership->getForAuthorization($actor, $company)?->role !== CompanyRole::Owner)) {
            throw new AuthorizationException;
        }
    }
}
