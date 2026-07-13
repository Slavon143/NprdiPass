<?php

namespace App\Http\Controllers;

use App\Enums\CompanyRole;
use App\Models\CompanyInvitation;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;

class CompanyMembersController extends Controller
{
    public function __invoke(CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->require();
        $this->authorize('viewAny', [CompanyMembership::class, $company]);

        $currentMembership = request()->attributes->get('currentMembership');
        abort_unless($currentMembership instanceof CompanyMembership, 403);

        $memberships = $company->memberships()
            ->with('user')
            ->orderByRaw("CASE role WHEN 'owner' THEN 1 WHEN 'admin' THEN 2 WHEN 'editor' THEN 3 WHEN 'viewer' THEN 4 ELSE 5 END")
            ->orderBy(
                User::query()
                    ->select('name')
                    ->whereColumn('users.id', 'company_user.user_id')
                    ->limit(1),
            )
            ->paginate(25);

        $roleOptions = $currentMembership->role === CompanyRole::Owner
            ? CompanyRole::cases()
            : array_values(array_filter(
                CompanyRole::cases(),
                fn (CompanyRole $role): bool => $role !== CompanyRole::Owner,
            ));

        $user = request()->user();
        abort_unless($user instanceof User, 401);
        $canManageInvitations = $user->can('viewAny', [CompanyInvitation::class, $company]);
        $invitations = $canManageInvitations
            ? $company->invitations()
                ->with('inviter')
                ->orderByRaw(
                    'CASE WHEN accepted_at IS NULL AND cancelled_at IS NULL AND expires_at > ? THEN 0 ELSE 1 END',
                    [now()],
                )
                ->orderByDesc('created_at')
                ->paginate(15, ['*'], 'invitationsPage')
                ->withQueryString()
            : null;
        $invitationRoleOptions = $currentMembership->role === CompanyRole::Owner
            ? CompanyRole::cases()
            : array_values(array_filter(
                CompanyRole::cases(),
                fn (CompanyRole $role): bool => $role !== CompanyRole::Owner,
            ));

        return view()->make('settings.members.index', [
            'company' => $company,
            'currentMembership' => $currentMembership,
            'memberships' => $memberships,
            'ownerCount' => $company->memberships()
                ->where('role', CompanyRole::Owner->value)
                ->count(),
            'roleOptions' => $roleOptions,
            'canManageInvitations' => $canManageInvitations,
            'invitations' => $invitations,
            'invitationRoleOptions' => $invitationRoleOptions,
        ]);
    }
}
