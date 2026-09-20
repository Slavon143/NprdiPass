<?php

namespace App\Services\Privacy;

use App\Audit\AuditLogger;
use App\Domain\Companies\Exceptions\LastCompanyOwnerCannotBeRemoved;
use App\Enums\AuditEvent;
use App\Enums\CompanyRole;
use App\Enums\UserStatus;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountErasureService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Erase the user's personal data (GDPR right to erasure).
     *
     * Personal data is anonymized immediately; the row is soft-deleted and
     * kept only for referential integrity until `privacy:purge` hard-deletes
     * it after the configured retention period. Business records (tenant
     * catalog data, immutable audit history) are not deleted.
     *
     * @throws LastCompanyOwnerCannotBeRemoved When the user is the last owner of a company.
     */
    public function erase(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->assertNotLastOwner($user);

            // Revoke all API tokens issued to the user.
            $user->tokens()->get()->each->delete();

            // Remove personal-data rows bound to the user.
            $user->notificationPreferences()->delete();
            $user->tenantNotifications()->delete();
            $user->memberships()->delete();

            // Anonymize immediately; unique email derived from the UUID.
            $user->forceFill([
                'name' => 'Deleted user',
                'email' => 'erased-'.$user->uuid.'@erased.invalid',
                'password' => Str::random(64),
                'status' => UserStatus::Suspended,
                'remember_token' => null,
                'email_verified_at' => null,
                'last_login_at' => null,
            ])->save();

            $user->delete();

            $this->auditLogger->logPlatform(AuditEvent::GdprErasure, $user, null, [
                'erased_user_uuid' => $user->uuid,
            ]);
        });
    }

    private function assertNotLastOwner(User $user): void
    {
        $ownedCompanyIds = CompanyMembership::query()
            ->where('user_id', $user->getKey())
            ->where('role', CompanyRole::Owner->value)
            ->pluck('company_id');

        foreach ($ownedCompanyIds as $companyId) {
            $otherOwnerExists = CompanyMembership::query()
                ->where('company_id', $companyId)
                ->where('role', CompanyRole::Owner->value)
                ->where('user_id', '!=', $user->getKey())
                ->lockForUpdate()
                ->exists();

            if (! $otherOwnerExists) {
                throw new LastCompanyOwnerCannotBeRemoved;
            }
        }
    }
}
