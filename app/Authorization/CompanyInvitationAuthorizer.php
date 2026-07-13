<?php

namespace App\Authorization;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Tenancy\CurrentMembership;
use Illuminate\Auth\Access\AuthorizationException;

class CompanyInvitationAuthorizer
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly CurrentMembership $currentMembership,
    ) {}

    public function authorizeRole(User $actor, Company $company, CompanyRole $role): void
    {
        $this->authorizer->authorize($actor, $company, CompanyPermission::MembersInvite);

        $actorRole = $this->currentMembership->get($actor, $company)?->getAttribute('role');

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
        if (! $this->allowsManage($actor, $invitation)) {
            throw new AuthorizationException;
        }
    }
}
