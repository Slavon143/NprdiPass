<?php

namespace App\Services\Privacy;

use App\Audit\AuditLogger;
use App\Domain\Companies\Exceptions\LastCompanyOwnerCannotBeRemoved;
use App\Enums\AuditEvent;
use App\Enums\CompanyRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            // Discover without child locks, then follow the tenant -> User ->
            // membership order used by mutation authorization. A current read
            // below rejects tenants added behind an older transaction snapshot.
            $companyIds = CompanyMembership::query()->where('user_id', $user->getKey())
                ->orderBy('company_id')->pluck('company_id')->all();
            foreach ($companyIds as $companyId) {
                Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            }
            $user = User::withTrashed()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $memberships = CompanyMembership::query()->where('user_id', $user->getKey())
                ->orderBy('company_id')->lockForUpdate()->get();
            if (array_diff($memberships->pluck('company_id')->all(), $companyIds) !== []) {
                throw ValidationException::withMessages([
                    'password' => 'Your company memberships changed. Try deleting your account again.',
                ]);
            }
            $this->assertNotLastOwner($user, $memberships->where('role', CompanyRole::Owner)->pluck('company_id')->all());

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

    /** @param list<int> $ownedCompanyIds */
    private function assertNotLastOwner(User $user, array $ownedCompanyIds): void
    {
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
