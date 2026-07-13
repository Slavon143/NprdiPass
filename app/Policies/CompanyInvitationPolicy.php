<?php

namespace App\Policies;

use App\Authorization\CompanyAuthorizer;
use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;

class CompanyInvitationPolicy
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
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
        $freshInvitation = CompanyInvitation::query()->find($invitation->getKey());

        if ($freshInvitation === null) {
            return false;
        }

        $company = Company::query()->find($freshInvitation->getAttribute('company_id'));

        return $company !== null
            && $this->authorizer->allows($user, $company, CompanyPermission::MembersInvite);
    }
}
