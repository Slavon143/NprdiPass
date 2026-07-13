<?php

namespace App\Actions\Companies;

use App\Audit\AuditLogger;
use App\Audit\AuditSnapshot;
use App\Authorization\CompanyAuthorizer;
use App\Domain\Companies\Exceptions\CannotRemoveOwnCompanyMembership;
use App\Domain\Companies\Exceptions\LastCompanyOwnerCannotBeRemoved;
use App\Enums\AuditEvent;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\CurrentMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class RemoveCompanyMember
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly CurrentMembership $currentMembership,
        private readonly AuditLogger $auditLogger,
        private readonly AuditSnapshot $auditSnapshot,
    ) {}

    public function execute(User $actor, CompanyMembership $membership): void
    {
        DB::transaction(function () use ($actor, $membership): void {
            $membershipSnapshot = CompanyMembership::query()->findOrFail($membership->getKey());

            // Serialize owner mutations per company before locking membership rows.
            $company = Company::query()
                ->whereKey($membershipSnapshot->getAttribute('company_id'))
                ->lockForUpdate()
                ->firstOrFail();

            $lockedMembership = CompanyMembership::query()
                ->whereKey($membership->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeActiveCompany($actor, $company);

            $actorMembership = $this->currentMembership->get($actor, $company)
                ?? throw new AuthorizationException;
            $actorRole = $this->roleOf($actorMembership);
            $targetRole = $this->roleOf($lockedMembership);
            $targetUser = User::withTrashed()
                ->whereKey($lockedMembership->getAttribute('user_id'))
                ->firstOrFail();

            if ($actorRole === CompanyRole::Admin && $targetRole === CompanyRole::Owner) {
                throw new AuthorizationException;
            }

            // Lock owner rows in stable order before checking and deleting.
            $owners = CompanyMembership::query()
                ->where('company_id', $company->getKey())
                ->where('role', CompanyRole::Owner->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            if ($targetRole === CompanyRole::Owner && $owners->count() <= 1) {
                throw new LastCompanyOwnerCannotBeRemoved;
            }

            if ($lockedMembership->getAttribute('user_id') === $actor->getKey()) {
                throw new CannotRemoveOwnCompanyMembership;
            }

            $lockedMembership->delete();

            $this->auditLogger->logTenant(
                $company,
                AuditEvent::MemberRemoved,
                $actor,
                $lockedMembership,
                array_merge($this->auditSnapshot->member($targetUser), [
                    'removed_role' => $targetRole->value,
                ]),
            );
        });
    }

    private function authorizeActiveCompany(User $actor, Company $company): void
    {
        if ($company->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $company, CompanyPermission::MembersRemove);
    }

    private function roleOf(CompanyMembership $membership): CompanyRole
    {
        $role = $membership->getAttribute('role');

        if (! $role instanceof CompanyRole) {
            throw new UnexpectedValueException('Company membership has an invalid role.');
        }

        return $role;
    }
}
