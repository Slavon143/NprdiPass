<?php

namespace App\Actions\Companies;

use App\Domain\Invitations\Exceptions\InvitationCannotBeAccepted;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Security\EmailNormalizer;
use App\Security\InvitationTokenVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AcceptCompanyInvitation
{
    public function __construct(
        private readonly InvitationTokenVerifier $tokenVerifier,
        private readonly EmailNormalizer $emailNormalizer,
    ) {}

    public function execute(
        CompanyInvitation $invitation,
        User $user,
        string $plainTextToken,
    ): CompanyMembership {
        try {
            return DB::transaction(function () use ($invitation, $user, $plainTextToken): CompanyMembership {
                $snapshot = CompanyInvitation::query()->find($invitation->getKey())
                    ?? throw new InvitationCannotBeAccepted;
                $company = Company::query()
                    ->whereKey($snapshot->getAttribute('company_id'))
                    ->lockForUpdate()
                    ->first();

                if ($company === null || $company->status !== CompanyStatus::Active) {
                    throw new InvitationCannotBeAccepted('This company is not available.');
                }

                $lockedInvitation = CompanyInvitation::query()
                    ->whereKey($snapshot->getKey())
                    ->where('company_id', $company->getKey())
                    ->lockForUpdate()
                    ->first()
                    ?? throw new InvitationCannotBeAccepted;

                if (! $this->tokenVerifier->verify($lockedInvitation, $plainTextToken)) {
                    throw new InvitationCannotBeAccepted;
                }

                if (! $lockedInvitation->isPending()) {
                    throw new InvitationCannotBeAccepted('This invitation is no longer available.');
                }

                $lockedUser = User::query()
                    ->whereKey($user->getKey())
                    ->lockForUpdate()
                    ->first()
                    ?? throw new InvitationCannotBeAccepted;
                $userStatus = $lockedUser->getAttribute('status');

                if (! $userStatus instanceof UserStatus) {
                    throw new InvitationCannotBeAccepted;
                }

                if ($userStatus === UserStatus::Suspended) {
                    throw new InvitationCannotBeAccepted('Suspended users cannot accept invitations.');
                }

                $userEmail = $this->emailNormalizer->normalize((string) $lockedUser->getAttribute('email'));
                $invitationEmail = $this->emailNormalizer->normalize((string) $lockedInvitation->getAttribute('email'));

                if (! hash_equals($invitationEmail, $userEmail)) {
                    throw new InvitationCannotBeAccepted('Sign in with the email address that received this invitation.');
                }

                $duplicateMembership = CompanyMembership::query()
                    ->where('company_id', $company->getKey())
                    ->where('user_id', $lockedUser->getKey())
                    ->lockForUpdate()
                    ->exists();

                if ($duplicateMembership) {
                    throw new InvitationCannotBeAccepted('This user is already a company member.');
                }

                $role = $lockedInvitation->getAttribute('role');

                if (! $role instanceof CompanyRole) {
                    throw new InvitationCannotBeAccepted;
                }

                $membership = new CompanyMembership;
                $membership->company_id = $company->getKey();
                $membership->user_id = $lockedUser->getKey();
                $membership->role = $role;
                $membership->joined_at = now();
                $membership->save();

                if ($userStatus === UserStatus::Invited) {
                    $lockedUser->setAttribute('status', UserStatus::Active);
                }

                if ($lockedUser->getAttribute('email_verified_at') === null) {
                    $lockedUser->setAttribute('email_verified_at', now());
                }

                if ($lockedUser->isDirty()) {
                    $lockedUser->save();
                }

                $lockedInvitation->setAttribute('accepted_at', now());
                $lockedInvitation->save();

                return $membership;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new InvitationCannotBeAccepted('This invitation has already been accepted.');
            }

            throw $exception;
        }
    }
}
