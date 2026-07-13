<?php

namespace App\Http\Controllers;

use App\Actions\Companies\ResendCompanyInvitation;
use App\Domain\Invitations\Exceptions\InvitationCannotBeResent;
use App\Http\Requests\ResendCompanyInvitationRequest;
use App\Models\User;
use App\Notifications\SendCompanyInvitationNotification;
use Illuminate\Http\RedirectResponse;

class ResendCompanyInvitationController extends Controller
{
    public function __invoke(
        ResendCompanyInvitationRequest $request,
        ResendCompanyInvitation $action,
        SendCompanyInvitationNotification $notification,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        try {
            $pendingInvitation = $action->execute($actor, $request->invitation());
        } catch (InvitationCannotBeResent $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $notification->send($pendingInvitation, $actor);

        return back()->with('success', 'Invitation resent with a new secure link.');
    }
}
