<?php

namespace App\Http\Controllers;

use App\Models\CompanyInvitation;
use App\Models\User;
use App\Security\EmailNormalizer;
use App\Security\InvitationTokenVerifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ShowCompanyInvitationController extends Controller
{
    public function __invoke(
        Request $request,
        CompanyInvitation $invitation,
        InvitationTokenVerifier $tokenVerifier,
        EmailNormalizer $emailNormalizer,
    ): View {
        $plainTextToken = $request->query('token');
        abort_unless(is_string($plainTextToken), 404);
        abort_unless($tokenVerifier->verify($invitation, $plainTextToken), 404);

        $invitation->loadMissing(['company', 'inviter']);
        abort_if($invitation->company === null, 404);

        $state = match (true) {
            $invitation->isAccepted() => 'accepted',
            $invitation->isCancelled() => 'cancelled',
            $invitation->isExpired() => 'expired',
            default => 'valid',
        };
        $normalizedEmail = $emailNormalizer->normalize((string) $invitation->getAttribute('email'));
        $hasAccount = User::withTrashed()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->exists();
        $user = $request->user();
        $authenticatedEmailMatches = $user instanceof User
            && hash_equals(
                $normalizedEmail,
                $emailNormalizer->normalize((string) $user->getAttribute('email')),
            );

        if ($state === 'valid' && ! $user instanceof User && $hasAccount) {
            $request->session()->put('url.intended', route('invitations.show', [
                'invitation' => $invitation,
                'token' => $plainTextToken,
            ]));
        }

        return view()->make('invitations.show', [
            'invitation' => $invitation,
            'plainTextToken' => $plainTextToken,
            'state' => $state,
            'hasAccount' => $hasAccount,
            'authenticatedEmailMatches' => $authenticatedEmailMatches,
        ]);
    }
}
