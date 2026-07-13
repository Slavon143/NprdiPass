<?php

namespace App\Actions\Companies;

use App\Audit\AuditLogger;
use App\Audit\AuditSnapshot;
use App\Authorization\CompanyInvitationAuthorizer;
use App\Domain\Invitations\Exceptions\CompanyMemberAlreadyExists;
use App\Domain\Invitations\PendingInvitation;
use App\Enums\AuditEvent;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Security\EmailNormalizer;
use App\Security\InvitationTokenGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class InviteCompanyMember
{
    public function __construct(
        private readonly CompanyInvitationAuthorizer $authorizer,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly InvitationTokenGenerator $tokenGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly AuditSnapshot $auditSnapshot,
    ) {}

    public function execute(
        User $actor,
        Company $company,
        string $email,
        CompanyRole $role,
    ): PendingInvitation {
        return $this->create($actor, $company, $email, $role, AuditEvent::MemberInvited);
    }

    public function resend(
        User $actor,
        Company $company,
        string $email,
        CompanyRole $role,
    ): PendingInvitation {
        return $this->create(
            $actor,
            $company,
            $email,
            $role,
            AuditEvent::MemberInvitationResent,
        );
    }

    private function create(
        User $actor,
        Company $company,
        string $email,
        CompanyRole $role,
        AuditEvent $auditEvent,
    ): PendingInvitation {
        $normalizedEmail = $this->emailNormalizer->normalize($email);

        return DB::transaction(function () use (
            $actor,
            $company,
            $normalizedEmail,
            $role,
            $auditEvent,
        ): PendingInvitation {
            $lockedCompany = Company::query()
                ->whereKey($company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCompany->status !== CompanyStatus::Active) {
                throw new AuthorizationException;
            }

            $this->authorizer->authorizeRole($actor, $lockedCompany, $role);

            $existingMember = User::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->whereHas('memberships', fn ($query) => $query->where(
                    'company_id',
                    $lockedCompany->getKey(),
                ))
                ->exists();

            if ($existingMember) {
                throw new CompanyMemberAlreadyExists;
            }

            $pendingInvitations = CompanyInvitation::query()
                ->where('company_id', $lockedCompany->getKey())
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->whereNull('accepted_at')
                ->whereNull('cancelled_at')
                ->where('expires_at', '>', now())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($pendingInvitations as $pendingInvitation) {
                $pendingInvitation->setAttribute('cancelled_at', now());
                $pendingInvitation->save();
            }

            $token = $this->tokenGenerator->generate();
            $expiresHours = max(1, (int) config('invitations.expires_hours', 72));

            $invitation = new CompanyInvitation;
            $invitation->email = $normalizedEmail;
            $invitation->role = $role;
            $invitation->expires_at = now()->addHours($expiresHours);
            $invitation->setAttribute('company_id', $lockedCompany->getKey());
            $invitation->setAttribute('token_hash', $token->hash());
            $invitation->setAttribute('invited_by', $actor->getKey());
            $invitation->save();

            $this->auditLogger->logTenant(
                $lockedCompany,
                $auditEvent,
                $actor,
                $invitation,
                $this->auditSnapshot->invitation($invitation),
            );

            return new PendingInvitation($invitation, $token->plainText());
        });
    }
}
