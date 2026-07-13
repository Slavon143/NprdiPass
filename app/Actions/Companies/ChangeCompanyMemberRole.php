<?php

namespace App\Actions\Companies;

use App\Authorization\CompanyAuthorizer;
use App\Domain\Companies\Exceptions\LastCompanyOwnerCannotBeRemoved;
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

class ChangeCompanyMemberRole
{
    public function __construct(
        private readonly CompanyAuthorizer $authorizer,
        private readonly CurrentMembership $currentMembership,
    ) {}

    public function execute(User $actor, CompanyMembership $membership, CompanyRole $newRole): void
    {
        DB::transaction(function () use ($actor, $membership, $newRole): void {
            $membershipSnapshot = CompanyMembership::query()->findOrFail($membership->getKey());

            // The company row serializes competing owner mutations for this tenant.
            $company = Company::query()
                ->whereKey($membershipSnapshot->getAttribute('company_id'))
                ->lockForUpdate()
                ->firstOrFail();

            $lockedMembership = CompanyMembership::query()
                ->whereKey($membership->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeActiveCompany($actor, $company, CompanyPermission::MembersUpdateRole);

            $actorMembership = $this->currentMembership->get($actor, $company)
                ?? throw new AuthorizationException;
            $actorRole = $this->roleOf($actorMembership);
            $targetRole = $this->roleOf($lockedMembership);

            if ($actorRole === CompanyRole::Admin && (
                $targetRole === CompanyRole::Owner || $newRole === CompanyRole::Owner
            )) {
                throw new AuthorizationException;
            }

            if ($newRole === CompanyRole::Owner && $actorRole !== CompanyRole::Owner) {
                throw new AuthorizationException;
            }

            // Lock owner rows in stable order before checking the minimum-owner invariant.
            $owners = CompanyMembership::query()
                ->where('company_id', $company->getKey())
                ->where('role', CompanyRole::Owner->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            if (
                $targetRole === CompanyRole::Owner
                && $newRole !== CompanyRole::Owner
                && $owners->count() <= 1
            ) {
                throw new LastCompanyOwnerCannotBeRemoved;
            }

            $lockedMembership->role = $newRole;
            $lockedMembership->save();
        });

        $membership->refresh();
    }

    private function authorizeActiveCompany(
        User $actor,
        Company $company,
        CompanyPermission $permission,
    ): void {
        if ($company->status !== CompanyStatus::Active) {
            throw new AuthorizationException;
        }

        $this->authorizer->authorize($actor, $company, $permission);
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
