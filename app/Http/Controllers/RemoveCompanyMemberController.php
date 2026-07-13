<?php

namespace App\Http\Controllers;

use App\Actions\Companies\RemoveCompanyMember;
use App\Domain\Companies\Exceptions\CannotRemoveOwnCompanyMembership;
use App\Domain\Companies\Exceptions\LastCompanyOwnerCannotBeRemoved;
use App\Http\Requests\RemoveCompanyMemberRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class RemoveCompanyMemberController extends Controller
{
    public function __invoke(
        RemoveCompanyMemberRequest $request,
        RemoveCompanyMember $action,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $action->execute($user, $request->membership());
        } catch (LastCompanyOwnerCannotBeRemoved) {
            return back()->with('error', 'The last owner cannot be removed.');
        } catch (CannotRemoveOwnCompanyMembership) {
            return back()->with('error', 'Use the dedicated leave flow to leave a company.');
        }

        return back()->with('success', 'Member removed.');
    }
}
