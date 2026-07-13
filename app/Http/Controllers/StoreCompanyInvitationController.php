<?php

namespace App\Http\Controllers;

use App\Actions\Companies\InviteCompanyMember;
use App\Domain\Invitations\Exceptions\CompanyMemberAlreadyExists;
use App\Enums\CompanyRole;
use App\Http\Requests\StoreCompanyInvitationRequest;
use App\Models\User;
use App\Notifications\SendCompanyInvitationNotification;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;

class StoreCompanyInvitationController extends Controller
{
    public function __invoke(
        StoreCompanyInvitationRequest $request,
        CurrentCompany $currentCompany,
        InviteCompanyMember $action,
        SendCompanyInvitationNotification $notification,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        try {
            $pendingInvitation = $action->execute(
                $actor,
                $currentCompany->require(),
                $request->string('email')->toString(),
                CompanyRole::from($request->string('role')->toString()),
            );
        } catch (CompanyMemberAlreadyExists $exception) {
            return back()->withInput()->withErrors(['email' => $exception->getMessage()]);
        }

        $notification->send($pendingInvitation, $actor);

        return back()->with('success', 'Invitation sent.');
    }
}
