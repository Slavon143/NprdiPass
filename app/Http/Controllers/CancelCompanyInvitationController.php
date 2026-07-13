<?php

namespace App\Http\Controllers;

use App\Actions\Companies\CancelCompanyInvitation;
use App\Domain\Invitations\Exceptions\InvitationCannotBeCancelled;
use App\Http\Requests\CancelCompanyInvitationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class CancelCompanyInvitationController extends Controller
{
    public function __invoke(
        CancelCompanyInvitationRequest $request,
        CancelCompanyInvitation $action,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        try {
            $action->execute($actor, $request->invitation());
        } catch (InvitationCannotBeCancelled $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Invitation cancelled.');
    }
}
