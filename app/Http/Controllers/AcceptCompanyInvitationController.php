<?php

namespace App\Http\Controllers;

use App\Actions\Companies\AcceptCompanyInvitation;
use App\Domain\Invitations\Exceptions\InvitationCannotBeAccepted;
use App\Http\Requests\AcceptCompanyInvitationRequest;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Security\InvitationTokenVerifier;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Http\RedirectResponse;

class AcceptCompanyInvitationController extends Controller
{
    public function __invoke(
        AcceptCompanyInvitationRequest $request,
        CompanyInvitation $invitation,
        InvitationTokenVerifier $tokenVerifier,
        AcceptCompanyInvitation $action,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $plainTextToken = $request->string('token')->toString();
        abort_unless($tokenVerifier->verify($invitation, $plainTextToken), 404);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $membership = $action->execute($invitation, $user, $plainTextToken);
        } catch (InvitationCannotBeAccepted $exception) {
            return redirect()->route('invitations.show', [
                'invitation' => $invitation,
                'token' => $plainTextToken,
            ])->with('error', $exception->getMessage());
        }

        $company = Company::query()->findOrFail($membership->getAttribute('company_id'));
        $currentCompany->set($company);

        return redirect()->route('dashboard')->with('success', 'Invitation accepted.');
    }
}
