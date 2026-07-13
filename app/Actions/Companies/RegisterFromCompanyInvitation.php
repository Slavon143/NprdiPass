<?php

namespace App\Actions\Companies;

use App\Domain\Invitations\Exceptions\InvitationRegistrationUnavailable;
use App\Domain\Invitations\InvitationRegistrationResult;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Security\EmailNormalizer;
use App\Security\InvitationTokenVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RegisterFromCompanyInvitation
{
    public function __construct(
        private readonly AcceptCompanyInvitation $acceptInvitation,
        private readonly InvitationTokenVerifier $tokenVerifier,
        private readonly EmailNormalizer $emailNormalizer,
    ) {}

    public function execute(
        CompanyInvitation $invitation,
        string $plainTextToken,
        string $name,
        string $password,
    ): InvitationRegistrationResult {
        try {
            return DB::transaction(function () use (
                $invitation,
                $plainTextToken,
                $name,
                $password,
            ): InvitationRegistrationResult {
                $snapshot = CompanyInvitation::query()->find($invitation->getKey())
                    ?? throw new InvitationRegistrationUnavailable;
                $company = Company::query()
                    ->whereKey($snapshot->getAttribute('company_id'))
                    ->lockForUpdate()
                    ->first()
                    ?? throw new InvitationRegistrationUnavailable;
                $lockedInvitation = CompanyInvitation::query()
                    ->whereKey($snapshot->getKey())
                    ->where('company_id', $company->getKey())
                    ->lockForUpdate()
                    ->first()
                    ?? throw new InvitationRegistrationUnavailable;

                if (
                    ! $this->tokenVerifier->verify($lockedInvitation, $plainTextToken)
                    || ! $lockedInvitation->isPending()
                ) {
                    throw new InvitationRegistrationUnavailable;
                }

                $email = $this->emailNormalizer->normalize((string) $lockedInvitation->getAttribute('email'));
                $existingUser = User::withTrashed()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->lockForUpdate()
                    ->exists();

                if ($existingUser) {
                    throw new InvitationRegistrationUnavailable('An account already exists for this email. Sign in instead.');
                }

                $user = new User;
                $user->name = trim($name);
                $user->email = $email;
                $user->password = $password;
                $user->setAttribute('status', UserStatus::Active);
                $user->setAttribute('email_verified_at', now());
                $user->save();

                $membership = $this->acceptInvitation->execute(
                    $lockedInvitation,
                    $user,
                    $plainTextToken,
                );

                return new InvitationRegistrationResult($user, $membership);
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new InvitationRegistrationUnavailable;
            }

            throw $exception;
        }
    }
}
