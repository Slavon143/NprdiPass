<?php

namespace App\Policies;

use App\Authorization\CompanyAuthorizer;
use App\Authorization\CompanyInvitationAuthorizer;
use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;

class CompanyInvitationPolicy
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly CompanyInvitationAuthorizer $invitationAuthorizer,
    ) {}

    public function viewAny(User $user, Company $company): bool
    {
        return $this->authorizer->allows($user, $company, CompanyPermission::MembersInvite);
    }

    public function create(User $user, Company $company): bool
    {
        return $this->authorizer->allows($user, $company, CompanyPermission::MembersInvite);
    }

    public function delete(User $user, CompanyInvitation $invitation): bool
    {
        return $this->invitationAuthorizer->allowsManage($user, $invitation);
    }

    public function resend(User $user, CompanyInvitation $invitation): bool
    {
        return $this->invitationAuthorizer->allowsManage($user, $invitation);
    }
}
