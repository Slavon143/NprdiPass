<?php

namespace App\Http\Controllers;

use App\Actions\Companies\RegisterFromCompanyInvitation;
use App\Domain\Invitations\Exceptions\InvitationRegistrationUnavailable;
use App\Http\Requests\RegisterCompanyInvitationRequest;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Security\EmailNormalizer;
use App\Security\InvitationTokenVerifier;
use App\Tenancy\Contracts\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompanyInvitationRegistrationController extends Controller
{
    public function create(
        Request $request,
        CompanyInvitation $invitation,
        InvitationTokenVerifier $tokenVerifier,
        EmailNormalizer $emailNormalizer,
    ): View|RedirectResponse {
        $plainTextToken = $request->query('token');
        abort_unless(is_string($plainTextToken), 404);
        abort_unless($tokenVerifier->verify($invitation, $plainTextToken), 404);

        if (! $invitation->isPending()) {
            return redirect()->route('invitations.show', [
                'invitation' => $invitation,
                'token' => $plainTextToken,
            ]);
        }

        $email = $emailNormalizer->normalize((string) $invitation->getAttribute('email'));

        if (User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return redirect()->route('invitations.show', [
                'invitation' => $invitation,
                'token' => $plainTextToken,
            ])->with('error', 'An account already exists for this email. Sign in instead.');
        }

        return view()->make('invitations.register', [
            'invitation' => $invitation,
            'plainTextToken' => $plainTextToken,
        ]);
    }

    public function store(
        RegisterCompanyInvitationRequest $request,
        CompanyInvitation $invitation,
        InvitationTokenVerifier $tokenVerifier,
        RegisterFromCompanyInvitation $action,
        CurrentCompany $currentCompany,
    ): RedirectResponse {
        $plainTextToken = $request->string('token')->toString();
        abort_unless($tokenVerifier->verify($invitation, $plainTextToken), 404);

        try {
            $result = $action->execute(
                $invitation,
                $plainTextToken,
                $request->string('name')->toString(),
                $request->string('password')->toString(),
            );
        } catch (InvitationRegistrationUnavailable $exception) {
            return back()->withInput()->withErrors(['invitation' => $exception->getMessage()]);
        }

        Auth::login($result->user);
        $request->session()->regenerate();
        $company = Company::query()->findOrFail($result->membership->getAttribute('company_id'));
        $currentCompany->set($company);

        return redirect()->route('dashboard')->with('success', 'Welcome to NordiPass. Your invitation was accepted.');
    }
}
