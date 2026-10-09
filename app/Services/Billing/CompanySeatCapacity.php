<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CompanySeatCapacity
{
    public function assertAvailable(Company $company, ?int $replacedInvitationId = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Seat admission requires the membership transaction.');
        }
        // Every membership/invitation writer uses this same tenant mutex.
        $company = Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
        if ($company->commercial_model_version !== 1) {
            return;
        }
        app(CommercialAccessPolicy::class)->assertWritable($company);
        $limit = app(EntitlementService::class)->limitForMutation($company, 'members.max');
        if ($limit === null) {
            throw ValidationException::withMessages(['entitlement' => 'The members.max allowance is unavailable.']);
        }
        // Locking reads observe current rows even inside an older caller snapshot.
        $members = CompanyMembership::query()->where('company_id', $company->getKey())
            ->orderBy('id')->sharedLock()->get(['id']);
        $pending = CompanyInvitation::query()->where('company_id', $company->getKey())
            ->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '>', now());
        if ($replacedInvitationId !== null) {
            $pending->whereKeyNot($replacedInvitationId);
        }
        $reserved = $pending->orderBy('id')->sharedLock()->get(['id']);
        if ($limit <= 0 || count($members) + count($reserved) >= $limit) {
            throw ValidationException::withMessages(['entitlement' => 'The members.max limit has been reached, including pending invitations.']);
        }
    }
}
