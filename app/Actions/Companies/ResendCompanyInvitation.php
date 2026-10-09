<?php

namespace App\Actions\Companies;

use App\Authorization\CompanyInvitationAuthorizer;
use App\Domain\Invitations\Exceptions\InvitationCannotBeResent;
use App\Domain\Invitations\PendingInvitation;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResendCompanyInvitation
{
    public function __construct(
        private readonly CompanyInvitationAuthorizer $authorizer,
        private readonly InviteCompanyMember $inviteCompanyMember,
    ) {}

    public function execute(User $actor, CompanyInvitation $invitation): PendingInvitation
    {
        return DB::transaction(function () use ($actor, $invitation): PendingInvitation {
            $this->authorizer->authorizeManage($actor, $invitation);
            $freshInvitation = CompanyInvitation::query()
                ->where('company_id', $invitation->getAttribute('company_id'))
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $freshInvitation->isPending()) {
                throw new InvitationCannotBeResent;
            }

            $company = Company::query()->findOrFail($freshInvitation->getAttribute('company_id'));
            $role = $freshInvitation->getAttribute('role');

            if (! $role instanceof CompanyRole) {
                throw new InvitationCannotBeResent;
            }

            return $this->inviteCompanyMember->resend(
                $actor,
                $company,
                (string) $freshInvitation->getAttribute('email'),
                $role,
            );
        });
    }
}
