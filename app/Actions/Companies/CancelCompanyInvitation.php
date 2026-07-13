<?php

namespace App\Actions\Companies;

use App\Audit\AuditLogger;
use App\Audit\AuditSnapshot;
use App\Authorization\CompanyInvitationAuthorizer;
use App\Domain\Invitations\Exceptions\InvitationCannotBeCancelled;
use App\Enums\AuditEvent;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CancelCompanyInvitation
{
    public function __construct(
        private readonly CompanyInvitationAuthorizer $authorizer,
        private readonly AuditLogger $auditLogger,
        private readonly AuditSnapshot $auditSnapshot,
    ) {}

    public function execute(User $actor, CompanyInvitation $invitation): void
    {
        DB::transaction(function () use ($actor, $invitation): void {
            $snapshot = CompanyInvitation::query()->findOrFail($invitation->getKey());
            $company = Company::query()
                ->whereKey($snapshot->getAttribute('company_id'))
                ->lockForUpdate()
                ->firstOrFail();
            $lockedInvitation = CompanyInvitation::query()
                ->whereKey($snapshot->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($company->status !== CompanyStatus::Active) {
                throw new AuthorizationException;
            }

            $this->authorizer->authorizeManage($actor, $lockedInvitation);

            if (! $lockedInvitation->isPending()) {
                throw new InvitationCannotBeCancelled;
            }

            $lockedInvitation->setAttribute('cancelled_at', now());
            $lockedInvitation->save();

            $this->auditLogger->logTenant(
                $company,
                AuditEvent::MemberInvitationCancelled,
                $actor,
                $lockedInvitation,
                $this->auditSnapshot->invitation($lockedInvitation),
            );
        });

        $invitation->refresh();
    }
}
